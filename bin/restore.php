<?php

declare(strict_types=1);

/**
 * Restores a VPN-GW backup (*.vgbk). Run as ROOT on the server:
 *   sudo php /srv/vpn-gw/bin/restore.php /var/lib/vpn-gw/backups/vpn-gw-20261115-020001-auto.vgbk          # show contents
 *   sudo php /srv/vpn-gw/bin/restore.php <file.vgbk> --apply                                              # restore
 * The passphrase is asked interactively (or taken from VPNGW_BACKUP_PASSPHRASE).
 * --apply puts back: the database, .env, /etc/vpn-gw, TLS certificates, CA files and the
 * Apache / PHP-FPM / nftables / logrotate configuration, then restarts the services.
 * The current database is kept as <DB_PATH>.before-restore-<time>.
 */

use VpnGw\Agent\BackupArchive;
use VpnGw\Agent\Proc;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (posix_getuid() !== 0) {
    fwrite(STDERR, "restore.php must run as root\n");
    exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';
umask(0077);

$file = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
if (!is_file($file)) {
    fwrite(STDERR, "Usage: restore.php <backup.vgbk> [--apply]\n");
    exit(2);
}
$pass = getenv('VPNGW_BACKUP_PASSPHRASE');
if (!is_string($pass) || $pass === '') {
    fwrite(STDOUT, 'Пароль резервної копії: ');
    shell_exec('stty -echo 2>/dev/null');
    $pass = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
}

$tmp = sys_get_temp_dir() . '/vpngw-restore-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
try {
    (new BackupArchive())->decrypt($file, "$tmp/payload.tar.gz", $pass);
    mkdir("$tmp/x", 0700);
    Proc::mustRun(['tar', '-xzpf', "$tmp/payload.tar.gz", '-C', "$tmp/x", '--same-owner', '--numeric-owner']);
    $manifest = json_decode((string) file_get_contents("$tmp/x/manifest.json"), true);
    if (!is_array($manifest) || ($manifest['product'] ?? '') !== 'VPN-GW') {
        throw new RuntimeException('manifest.json not found — not a VPN-GW backup');
    }
    fwrite(STDOUT, sprintf("Копія VPN-GW %s від %s (%s, %s)\n", $manifest['version'] ?? '?', $manifest['created'] ?? '?', $manifest['host'] ?? '?', $manifest['kind'] ?? '?'));
    foreach ((array) ($manifest['files'] ?? []) as $f) {
        fwrite(STDOUT, "  /$f\n");
    }
    fwrite(STDOUT, "  база даних (db.sqlite)\n");
    if (!$apply) {
        fwrite(STDOUT, "\nНічого не змінено. Для відновлення додайте --apply\n");
        exit(0);
    }

    $conf = [];
    foreach (file("$tmp/x/etc/vpn-gw/install.conf", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $l, $m)) {
            $conf[$m[1]] = $m[2];
        }
    }
    $target = $conf['TARGET'] ?? '/srv/vpn-gw';
    $user = $conf['APP_USER'] ?? 'vpngw';
    $phpv = $conf['PHPV'] ?? '8.4';
    $env = Dotenv\Dotenv::createArrayBacked("$tmp/x" . $target)->load();
    $dbPath = (string) ($env['DB_PATH'] ?? '/var/lib/vpn-gw/db.sqlite');

    Proc::run(['systemctl', 'stop', "php$phpv-fpm"]);
    // Put back exactly the paths listed in the manifest. Parent directories are never
    // copied over (their modes in the extraction directory are not the real ones).
    foreach ((array) ($manifest['files'] ?? []) as $rel) {
        if (!is_string($rel) || $rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            continue;
        }
        $src = "$tmp/x/$rel";
        $dst = '/' . $rel;
        if (!file_exists($src) && !is_link($src)) {
            continue;
        }
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0755, true);
        }
        if (is_dir($src) && !is_link($src)) {
            if (!is_dir($dst)) {
                mkdir($dst, 0700);
            }
            Proc::mustRun(['cp', '-a', "$src/.", "$dst/"]);
            Proc::mustRun(['chmod', '--reference=' . $src, $dst]);
            Proc::mustRun(['chown', '--reference=' . $src, $dst]);
        } else {
            Proc::mustRun(['cp', '-a', $src, $dst]);
        }
        fwrite(STDOUT, "  відновлено /$rel\n");
    }
    if (is_file($dbPath)) {
        rename($dbPath, $dbPath . '.before-restore-' . date('Ymd-His'));
    }
    @unlink($dbPath);
    @unlink("$dbPath-wal");
    @unlink("$dbPath-shm");
    copy("$tmp/x/db.sqlite", $dbPath);
    chown($dbPath, $user);
    chgrp($dbPath, $user);
    chmod($dbPath, 0600);
    Proc::run(['systemctl', 'start', "php$phpv-fpm"]);
    Proc::run(['systemctl', 'reload-or-restart', 'apache2']);
    fwrite(STDOUT, "\nВідновлено. Перевірте портал і, якщо версія коду інша, запустіть install.sh тієї версії, що в копії.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    Proc::run(['rm', '-rf', $tmp]);
}
