<?php

declare(strict_types=1);

namespace VpnGw\Admin;

/**
 * Web side of the privileged agent. The web process (user vpngw, PHP-FPM with
 * proc_open/exec disabled) cannot run anything as root; it only drops a job file
 * into the spool directory. systemd (vpn-gw-agent.path) starts bin/agent.php as root,
 * which validates the job and writes the status into DATA_DIR/agent/status.
 *
 * Directories:
 *   DATA_DIR/spool                 vpngw 0700       <id>.job, <id>.zip (uploaded release)
 *   AGENT_DIR/status (root-owned)  root:vpngw 0750  <id>.json, <id>.log (read-only for the web)
 *   BACKUP_DIR       (root-owned)  root:vpngw 0750  *.vgbk (encrypted backups, read-only for the web)
 * The agent's own directories are owned by root on purpose: the web user must not be
 * able to swap them for symlinks that root would then write through.
 *
 * @phpstan-type Job array{id: string, type: string, state: string, by: string, created: string,
 *   started?: string, finished?: string, message?: string, version?: string, file?: string}
 */
final class Jobs
{
    public const TYPES = ['backup', 'update'];
    public const ID_RE = '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/';
    public const BACKUP_RE = '/^vpn-gw-[0-9]{8}-[0-9]{6}-(auto|manual|preupdate)\.vgbk$/';

    public function __construct(
        private readonly string $spoolDir,
        private readonly string $statusDir,
        private readonly string $backupDir,
    ) {
    }

    public function spoolDir(): string
    {
        return $this->spoolDir;
    }

    public function statusDir(): string
    {
        return $this->statusDir;
    }

    public function backupDir(): string
    {
        return $this->backupDir;
    }

    public static function newId(\DateTimeImmutable $now): string
    {
        return $now->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Queues a job. The .job file is written under a temporary name and renamed,
     * so the agent never sees a half-written file.
     *
     * @param array<string, scalar> $params
     */
    public function enqueue(string $id, string $type, string $by, \DateTimeImmutable $now, array $params = []): void
    {
        if (!in_array($type, self::TYPES, true) || !preg_match(self::ID_RE, $id)) {
            throw new \InvalidArgumentException('Bad job');
        }
        $job = ['id' => $id, 'type' => $type, 'by' => $by, 'created' => $now->format('Y-m-d H:i:s')] + $params;
        $tmp = $this->spoolDir() . "/.$id.tmp";
        if (file_put_contents($tmp, json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write to the spool directory ' . $this->spoolDir());
        }
        if (!rename($tmp, $this->spoolDir() . "/$id.job")) {
            throw new \RuntimeException('Cannot queue the job');
        }
    }

    /** True while a job of this type is queued or running. */
    public function busy(string $type): bool
    {
        foreach ($this->list(20) as $job) {
            if ($job['type'] === $type && in_array($job['state'], ['queued', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<Job> newest first: queued jobs from the spool, then finished/running ones from the agent */
    public function list(int $limit = 30): array
    {
        $jobs = [];
        foreach (glob($this->statusDir() . '/*.json') ?: [] as $file) {
            $job = self::readJson($file);
            if ($job !== null) {
                $jobs[$job['id']] = $job;
            }
        }
        foreach (glob($this->spoolDir() . '/*.job') ?: [] as $file) {
            $job = self::readJson($file);
            if ($job !== null && !isset($jobs[$job['id']])) {
                $jobs[$job['id']] = ['state' => 'queued'] + $job;
            }
        }
        krsort($jobs);
        return array_slice(array_values($jobs), 0, $limit);
    }

    /** @return Job|null */
    public function get(string $id): ?array
    {
        if (!preg_match(self::ID_RE, $id)) {
            return null;
        }
        $job = self::readJson($this->statusDir() . "/$id.json");
        if ($job !== null) {
            return $job;
        }
        $queued = self::readJson($this->spoolDir() . "/$id.job");
        return $queued === null ? null : ['state' => 'queued'] + $queued;
    }

    /** Last part of the job log (installer output). */
    public function log(string $id, int $maxBytes = 65536): string
    {
        if (!preg_match(self::ID_RE, $id)) {
            return '';
        }
        $file = $this->statusDir() . "/$id.log";
        if (!is_file($file)) {
            return '';
        }
        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
        }
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        return ($size > $maxBytes ? "…\n" : '') . (string) preg_replace('/\e\[[0-9;]*m/', '', $data);
    }

    /** @return list<array{name: string, size: int, mtime: int}> newest first */
    public function backups(): array
    {
        $out = [];
        foreach (glob($this->backupDir() . '/*.vgbk') ?: [] as $file) {
            $name = basename($file);
            if (preg_match(self::BACKUP_RE, $name) && is_file($file)) {
                $out[] = ['name' => $name, 'size' => (int) filesize($file), 'mtime' => (int) filemtime($file)];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Full path of a backup for download, or null for anything that is not one of ours. */
    public function backupPath(string $name): ?string
    {
        if (!preg_match(self::BACKUP_RE, $name)) {
            return null;
        }
        $path = $this->backupDir() . '/' . $name;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    /** @return Job|null */
    private static function readJson(string $file): ?array
    {
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_string($data['id'] ?? null) || !preg_match(self::ID_RE, $data['id'])
            || !in_array($data['type'] ?? null, self::TYPES, true)) {
            return null;
        }
        $job = [
            'id' => $data['id'],
            'type' => (string) $data['type'],
            'state' => is_string($data['state'] ?? null) ? $data['state'] : 'queued',
            'by' => is_string($data['by'] ?? null) ? $data['by'] : '',
            'created' => is_string($data['created'] ?? null) ? $data['created'] : '',
        ];
        foreach (['started', 'finished', 'message', 'version', 'file'] as $k) {
            if (is_string($data[$k] ?? null)) {
                $job[$k] = $data[$k];
            }
        }
        return $job;
    }
}
