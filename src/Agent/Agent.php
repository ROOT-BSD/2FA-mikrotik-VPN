<?php

declare(strict_types=1);

namespace VpnGw\Agent;

use VpnGw\Admin\Jobs;

/**
 * Privileged agent (runs as root from systemd: vpn-gw-agent.service).
 *
 * Started by vpn-gw-agent.path when a job appears in the spool, and hourly by
 * vpn-gw-agent.timer. It performs only fixed, whitelisted actions:
 *   - backup: encrypted archive of the DB, .env, certificates and web-server config;
 *   - update: verify and install an uploaded release archive, with rollback;
 *   - tick:   scheduled backups, backup rotation, logrotate settings for LOG_PATH.
 *
 * Everything that touches the SQLite database runs as the application user through
 * bin/agent-helper.php (runuser), so root never creates files inside the DB directory.
 * Job files come from the less-privileged web process and are treated as untrusted.
 */
final class Agent
{
    public const INSTALL_CONF = '/etc/vpn-gw/install.conf';
    private const JOB_MAX_BYTES = 4096;

    /** @var array<string, mixed>|null */
    private ?array $appConfig = null;

    /** @param array{DOMAIN: string, TARGET: string, DATA_DIR: string, AGENT_DIR: string, BACKUP_DIR: string, APP_USER: string, PHPV: string} $conf */
    public function __construct(private readonly array $conf)
    {
    }

    public static function fromInstallConf(string $file = self::INSTALL_CONF): self
    {
        if (!is_file($file) || fileowner($file) !== 0) {
            throw new \RuntimeException("$file is missing or not owned by root (run install.sh)");
        }
        $conf = ['DOMAIN' => '', 'TARGET' => '/srv/vpn-gw', 'DATA_DIR' => '/var/lib/vpn-gw', 'AGENT_DIR' => '/var/lib/vpn-gw-agent',
            'BACKUP_DIR' => '/var/backups/vpn-gw', 'APP_USER' => 'vpngw', 'PHPV' => '8.4'];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m) && array_key_exists($m[1], $conf)) {
                $conf[$m[1]] = trim($m[2], "\"' ");
            }
        }
        if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $conf['DOMAIN'])
            || array_filter([$conf['TARGET'], $conf['DATA_DIR'], $conf['AGENT_DIR'], $conf['BACKUP_DIR']], static fn ($p) => !preg_match('#^/[A-Za-z0-9._/-]+$#', $p) || str_contains($p, '..')) !== []
            || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $conf['APP_USER']) || !preg_match('/^\d\.\d$/', $conf['PHPV'])) {
            throw new \RuntimeException("$file contains invalid values");
        }
        return new self($conf);
    }

    // ------------------------------------------------------------------ entry points

    /** Processes queued jobs, then the periodic checks. */
    public function run(): void
    {
        $lock = $this->lock();
        try {
            foreach (glob($this->spool() . '/*.job') ?: [] as $file) {
                $this->processJobFile($file);
            }
            $this->tick();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function tick(): void
    {
        $this->syncLogrotate();
        $cfg = $this->appConfig();
        if (!filter_var($cfg['BACKUP_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL)) {
            return;
        }
        $now = $this->now();
        $hour = (int) ($cfg['BACKUP_HOUR'] ?? 2);
        $todayPrefix = 'vpn-gw-' . $now->format('Ymd') . '-';
        $doneToday = array_filter($this->backupFiles(), static fn ($f) => str_starts_with($f, $todayPrefix) && str_ends_with($f, '-auto.vgbk'));
        if ((int) $now->format('G') >= $hour && $doneToday === []) {
            $id = Jobs::newId($now);
            $this->status($id, ['type' => 'backup', 'state' => 'running', 'by' => 'розклад', 'created' => $now->format('Y-m-d H:i:s'), 'started' => $now->format('Y-m-d H:i:s')]);
            $this->runBackupJob($id, 'auto', 'розклад');
        }
    }

    /** Creates a backup; returns the file name. */
    public function backup(string $kind, string $by): string
    {
        $cfg = $this->appConfig();
        $pass = (string) ($cfg['BACKUP_PASSPHRASE'] ?? '');
        if ($pass === '') {
            throw new \RuntimeException('Не задано пароль шифрування резервних копій (панель → Резервне копіювання)');
        }
        $now = $this->now();
        $name = 'vpn-gw-' . $now->format('Ymd-His') . "-$kind.vgbk";
        $work = $this->workDir('backup-' . $now->format('Ymd-His') . '-' . bin2hex(random_bytes(3)));
        try {
            // 1. Consistent DB copy, made by the application user.
            $snap = trim($this->helper('snapshot'));
            if (!is_file($snap) || is_link($snap) || !str_starts_with($snap, $this->dataDir() . '/tmp/')) {
                throw new \RuntimeException('DB snapshot failed');
            }
            if (!rename($snap, "$work/db.sqlite")) {
                throw new \RuntimeException('Cannot move the DB snapshot');
            }
            // 2. Files to include (only those that exist).
            $paths = [$this->target() . '/.env', self::INSTALL_CONF, '/etc/ssl/vpn-gw', '/etc/letsencrypt',
                '/etc/apache2/sites-available/vpn-gw.conf', "/etc/php/{$this->conf['PHPV']}/fpm/pool.d/vpngw.conf",
                '/etc/nftables.conf', '/etc/logrotate.d/vpn-gw'];
            foreach (['LDAP_CA_FILE', 'MT_CA_FILE', 'SMTP_CA_FILE'] as $k) {
                $f = (string) ($cfg[$k] ?? '');
                if ($f !== '' && str_starts_with($f, '/')) {
                    $paths[] = $f;
                }
            }
            $rel = [];
            foreach (array_unique($paths) as $p) {
                if (file_exists($p) && !str_contains($p, '..')) {
                    $rel[] = ltrim($p, '/');
                }
            }
            file_put_contents("$work/manifest.json", json_encode([
                'product' => 'VPN-GW', 'version' => (string) ($cfg['version'] ?? ''), 'created' => $now->format(DATE_ATOM),
                'host' => gethostname(), 'kind' => $kind, 'by' => $by, 'files' => $rel,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // 3. tar.gz, then encrypt.
            Proc::mustRun(['tar', '--numeric-owner', '-czf', "$work/payload.tar.gz", '-C', $work, 'manifest.json', 'db.sqlite', '-C', '/', ...$rel]);
            $tmp = $this->backupDir() . "/.$name.part";
            @unlink($tmp);
            (new BackupArchive())->encrypt("$work/payload.tar.gz", $tmp, $pass);
            $this->chownApp($tmp, 0640);
            rename($tmp, $this->backupDir() . "/$name");
        } finally {
            Proc::run(['rm', '-rf', '--one-file-system', $work]);
        }
        $this->rotateBackups((int) ($cfg['BACKUP_KEEP'] ?? 14));
        return $name;
    }

    // ------------------------------------------------------------------ jobs

    private function processJobFile(string $file): void
    {
        if (is_link($file) || !is_file($file) || filesize($file) > self::JOB_MAX_BYTES) {
            @unlink($file);
            return;
        }
        $raw = (string) file_get_contents($file);
        @unlink($file); // consume first: a bad job must not re-trigger the path unit forever
        $job = json_decode($raw, true);
        if (!is_array($job) || !is_string($job['id'] ?? null) || !preg_match(Jobs::ID_RE, $job['id'])
            || !in_array($job['type'] ?? null, Jobs::TYPES, true)) {
            return;
        }
        $id = $job['id'];
        $by = is_string($job['by'] ?? null) ? mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '', $job['by']) ?? '', 0, 32) : '';
        $created = is_string($job['created'] ?? null) && preg_match('/^[0-9: -]{19}$/', $job['created']) ? $job['created'] : '';
        $base = ['type' => $job['type'], 'by' => $by, 'created' => $created, 'state' => 'running', 'started' => $this->now()->format('Y-m-d H:i:s')];
        if ($job['type'] === 'backup') {
            $this->status($id, $base);
            $this->runBackupJob($id, 'manual', $by);
            return;
        }
        $sha = is_string($job['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $job['sha256']) ? $job['sha256'] : null;
        $version = is_string($job['version'] ?? null) && preg_match('/^\d{1,3}\.\d{1,3}(\.\d{1,3})?$/', $job['version']) ? $job['version'] : null;
        $this->status($id, $base + ['version' => (string) $version]);
        if ($sha === null || $version === null) {
            $this->finish($id, 'failed', 'Некоректне завдання оновлення');
            return;
        }
        $this->runUpdateJob($id, $sha, $version, ($job['allow_same'] ?? false) === true, $by);
    }

    private function runBackupJob(string $id, string $kind, string $by): void
    {
        try {
            $name = $this->backup($kind, $by);
            $this->finish($id, 'ok', "Створено $name", ['file' => $name]);
            $this->audit('backup_ok', ['file' => $name, 'kind' => $kind, 'by' => $by]);
        } catch (\Throwable $e) {
            $this->finish($id, 'failed', $e->getMessage());
            $this->audit('backup_error', ['error' => $e->getMessage(), 'kind' => $kind, 'by' => $by]);
        }
    }

    private function runUpdateJob(string $id, string $sha, string $version, bool $allowSame, string $by): void
    {
        $log = $this->statusDir() . "/$id.log";
        $say = function (string $msg) use ($log): void {
            file_put_contents($log, '[' . $this->now()->format('H:i:s') . "] $msg\n", FILE_APPEND);
        };
        touch($log);
        $this->chownApp($log, 0640);
        $upload = $this->spool() . "/$id.zip";
        $work = $this->workDir("update-$id");
        $target = $this->target();
        $rollback = "$work/rollback";
        $installed = false;
        try {
            if (is_link($upload) || !is_file($upload)) {
                throw new \RuntimeException('Файл архіву не знайдено');
            }
            copy($upload, "$work/release.zip");
            @unlink($upload);
            if (!hash_equals($sha, (string) hash_file('sha256', "$work/release.zip"))) {
                throw new \RuntimeException('Контрольна сума SHA-256 не збігається');
            }
            $say("SHA-256 OK: $sha");
            $inspector = new ReleaseInspector();
            $found = $inspector->inspect("$work/release.zip");
            if ($found !== $version) {
                throw new \RuntimeException("Версія в архіві ($found) не відповідає імені файлу ($version)");
            }
            $current = trim((string) @file_get_contents("$target/VERSION"));
            $cmp = version_compare($version, $current);
            if ($cmp < 0 || ($cmp === 0 && !$allowSame)) {
                throw new \RuntimeException("Версія $version не новіша за встановлену $current");
            }
            $say("Перевірку архіву пройдено: $current -> $version");
            mkdir("$work/src", 0700);
            $root = $inspector->extract("$work/release.zip", "$work/src", $version);

            $say('Резервна копія перед оновленням...');
            $say('Створено ' . $this->backup('preupdate', $by));

            $say("Знімок поточної версії ($target)...");
            Proc::mustRun(['cp', '-a', $target, $rollback]);
            foreach ($this->webConfigFiles() as $i => $f) {
                if (is_file($f)) {
                    copy($f, "$work/conf-$i");
                }
            }

            $say("Запуск install.sh --domain {$this->conf['DOMAIN']} --target $target --yes");
            $installed = true;
            [$code] = Proc::run(['timeout', '1200', 'bash', "$root/install.sh", '--domain', $this->conf['DOMAIN'], '--target', $target, '--yes'],
                $log, $root, ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => '/root', 'TERM' => 'dumb']);
            $say("install.sh завершився з кодом $code");
            if ($code === 1) {
                // Exit code 1 = finished with FAIL checks (e.g. a router is unreachable). The app itself may be fine.
                $code = str_starts_with(trim($this->helper('health')), 'ok ') ? 0 : 1;
                $say($code === 0 ? 'Перевірка застосунку: OK (є попередження інсталятора — див. журнал вище)' : 'Перевірка застосунку не пройдена');
            }
            if ($code !== 0) {
                throw new \RuntimeException("Інсталятор завершився з помилкою (код $code)");
            }
            $health = trim($this->helper('health'));
            if ($health !== "ok $version") {
                throw new \RuntimeException("Після оновлення застосунок не відповідає: $health");
            }
            $say("Оновлено до версії $version");
            $this->finish($id, 'ok', "Оновлено $current -> $version", ['version' => $version]);
            $this->audit('update_ok', ['from' => $current, 'to' => $version, 'by' => $by]);
            Proc::run(['rm', '-rf', '--one-file-system', $work]);
        } catch (\Throwable $e) {
            $say('ПОМИЛКА: ' . $e->getMessage());
            @unlink($upload);
            if ($installed && is_dir($rollback)) {
                $say('Відкат до попередньої версії...');
                try {
                    $this->rollback($id, $rollback, $work);
                    $say('Відкат виконано. Невдалу версію збережено в ' . $target . '.failed-' . $id . ' (можна видалити після аналізу)');
                    $e = new \RuntimeException($e->getMessage() . ' — виконано відкат до попередньої версії', 0, $e);
                    Proc::run(['rm', '-rf', '--one-file-system', $work]);
                } catch (\Throwable $r) {
                    $say('ВІДКАТ НЕ ВДАВСЯ: ' . $r->getMessage() . ". Знімок: $rollback");
                }
            } else {
                Proc::run(['rm', '-rf', '--one-file-system', $work]);
            }
            $this->finish($id, 'failed', $e->getMessage());
            $this->audit('update_error', ['to' => $version, 'error' => $e->getMessage(), 'by' => $by]);
        }
    }

    private function rollback(string $id, string $snapshot, string $work): void
    {
        $target = $this->target();
        Proc::mustRun(['mv', $target, "$target.failed-$id"]);
        Proc::mustRun(['mv', $snapshot, $target]);
        foreach ($this->webConfigFiles() as $i => $f) {
            if (is_file("$work/conf-$i")) {
                copy("$work/conf-$i", $f);
            }
        }
        Proc::run(['systemctl', 'restart', "php{$this->conf['PHPV']}-fpm"]);
        Proc::run(['systemctl', 'reload-or-restart', 'apache2']);
    }

    /** @return list<string> */
    private function webConfigFiles(): array
    {
        return ['/etc/apache2/sites-available/vpn-gw.conf', "/etc/php/{$this->conf['PHPV']}/fpm/pool.d/vpngw.conf",
            '/etc/logrotate.d/vpn-gw', '/etc/systemd/system/vpn-gw-agent.service'];
    }

    // ------------------------------------------------------------------ periodic

    /** Keeps /etc/logrotate.d/vpn-gw in line with LOG_PATH and LOG_RETENTION_DAYS. */
    public function syncLogrotate(): void
    {
        $cfg = $this->appConfig();
        $logPath = (string) ($cfg['LOG_PATH'] ?? '');
        $days = max(1, min(3650, (int) ($cfg['LOG_RETENTION_DAYS'] ?? 180)));
        $dirs = [$this->dataDir() . '/logs'];
        $dir = dirname($logPath);
        if (in_array($dir, ['/var/log/vpn-gw', $this->dataDir() . '/logs'], true)) {
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
                $this->chownApp($dir, 0750, true);
            }
            $dirs[] = $dir;
        }
        $user = $this->conf['APP_USER'];
        $body = "# Managed by VPN-GW agent (admin panel -> Журнали). Manual edits are overwritten.\n";
        foreach (array_unique($dirs) as $d) {
            $body .= "$d/*.log {\n    daily\n    rotate $days\n    maxage $days\n    compress\n    delaycompress\n    missingok\n    notifempty\n    su $user $user\n    create 0600 $user $user\n}\n";
        }
        $file = '/etc/logrotate.d/vpn-gw';
        if (!is_file($file) || file_get_contents($file) !== $body) {
            file_put_contents($file, $body);
            chmod($file, 0644);
        }
    }

    private function rotateBackups(int $keep): void
    {
        $files = $this->backupFiles();
        rsort($files);
        foreach (array_slice($files, max(1, $keep)) as $old) {
            @unlink($this->backupDir() . "/$old");
        }
    }

    /** @return list<string> */
    private function backupFiles(): array
    {
        $out = [];
        foreach (glob($this->backupDir() . '/*.vgbk') ?: [] as $f) {
            if (preg_match(Jobs::BACKUP_RE, basename($f))) {
                $out[] = basename($f);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function appConfig(): array
    {
        if ($this->appConfig === null) {
            $data = json_decode($this->helper('config'), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Cannot read application settings');
            }
            $this->appConfig = $data;
        }
        return $this->appConfig;
    }

    /** Runs bin/agent-helper.php as the application user and returns its stdout. */
    private function helper(string ...$args): string
    {
        [$code, $out, $err] = Proc::run(['runuser', '-u', $this->conf['APP_USER'], '--', 'php', $this->target() . '/bin/agent-helper.php', ...array_values($args)]);
        if ($code !== 0) {
            throw new \RuntimeException('agent-helper ' . ($args[0] ?? '') . ': ' . trim(mb_substr($err . $out, 0, 500)));
        }
        return $out;
    }

    /** @param array<string, scalar> $extra */
    private function audit(string $event, array $extra): void
    {
        try {
            $this->helper('audit', $event, json_encode($extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // the job status already records the outcome
        }
    }

    /** @param array<string, string> $data */
    private function status(string $id, array $data): void
    {
        $file = $this->statusDir() . "/$id.json";
        $old = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $merged = (is_array($old) ? $old : []) + [];
        foreach ($data as $k => $v) {
            $merged[$k] = $v;
        }
        $merged['id'] = $id;
        $tmp = "$file.tmp";
        file_put_contents($tmp, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->chownApp($tmp, 0640);
        rename($tmp, $file);
    }

    /** @param array<string, string> $extra */
    private function finish(string $id, string $state, string $message, array $extra = []): void
    {
        $this->status($id, ['state' => $state, 'message' => $message, 'finished' => $this->now()->format('Y-m-d H:i:s')] + $extra);
    }

    /** root:APP_USER ownership with the given mode (the web may read, never write). */
    private function chownApp(string $path, int $mode, bool $userOwns = false): void
    {
        if (posix_getuid() === 0) {
            chown($path, $userOwns ? $this->conf['APP_USER'] : 'root');
            chgrp($path, $this->conf['APP_USER']);
        }
        chmod($path, $mode);
    }

    private function workDir(string $name): string
    {
        $dir = $this->conf['AGENT_DIR'] . '/work/' . $name;
        if (!is_dir(dirname($dir))) {
            mkdir(dirname($dir), 0700, true);
        }
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        return $dir;
    }

    /** @return resource */
    private function lock()
    {
        foreach ([$this->conf['AGENT_DIR'], $this->statusDir(), $this->backupDir()] as $d) {
            self::assertRootDir($d);
        }
        $fh = fopen($this->conf['AGENT_DIR'] . '/agent.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the agent');
        }
        return $fh;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::systemTimezone());
    }

    public static function systemTimezone(): \DateTimeZone
    {
        $link = @readlink('/etc/localtime');
        if (is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
            try {
                return new \DateTimeZone($m[1]);
            } catch (\Exception) {
            }
        }
        return new \DateTimeZone('Europe/Kyiv');
    }

    private function target(): string
    {
        return $this->conf['TARGET'];
    }

    private function dataDir(): string
    {
        return $this->conf['DATA_DIR'];
    }

    private function spool(): string
    {
        return $this->dataDir() . '/spool';
    }

    private function statusDir(): string
    {
        return $this->conf['AGENT_DIR'] . '/status';
    }

    private function backupDir(): string
    {
        return $this->conf['BACKUP_DIR'];
    }

    /** The agent writes only into directories that root owns and nobody else can modify. */
    private static function assertRootDir(string $dir): void
    {
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir) || fileowner($dir) !== 0 || (fileperms($dir) & 0o022) !== 0) {
            throw new \RuntimeException("$dir must be a root-owned directory not writable by group/others (run install.sh)");
        }
    }
}
