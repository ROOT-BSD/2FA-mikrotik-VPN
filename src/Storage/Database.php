<?php

declare(strict_types=1);

namespace VpnGw\Storage;

use PDO;

/** Thin SQLite data-access layer. All SQL lives here. */
final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 3000');
        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
    }

    public static function inMemory(): self
    {
        $db = new self(':memory:');
        $db->migrate();
        return $db;
    }

    /** Applies every migrations/NNN_*.sql in order. Each file is idempotent. */
    public function migrate(): void
    {
        $files = glob(__DIR__ . '/migrations/[0-9][0-9][0-9]_*.sql');
        if ($files === false || $files === []) {
            throw new \RuntimeException('Migration files not found');
        }
        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read $file");
            }
            $this->pdo->exec($sql);
        }
    }

    /** Consistent online copy of the database (used for backups). */
    public function snapshotTo(string $path): void
    {
        if (file_exists($path)) {
            throw new \RuntimeException('Snapshot target already exists');
        }
        $this->pdo->prepare('VACUUM INTO ?')->execute([$path]);
    }

    public function hasTable(string $name): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return $st->fetchColumn() !== false;
    }

    // ---- TOTP --------------------------------------------------------------

    /** @return array{secret_enc: string, last_step: int}|null */
    public function findTotp(string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT secret_enc, last_step FROM totp_secrets WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        return ['secret_enc' => (string) $row['secret_enc'], 'last_step' => (int) $row['last_step']];
    }

    public function insertTotp(string $username, string $secretEnc, int $lastStep, string $createdBy, string $createdAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO totp_secrets (username, secret_enc, last_step, created_at, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $st->bindValue(1, $username);
        $st->bindValue(2, $secretEnc, PDO::PARAM_LOB);
        $st->bindValue(3, $lastStep, PDO::PARAM_INT);
        $st->bindValue(4, $createdAt);
        $st->bindValue(5, $createdBy);
        $st->execute();
    }

    /**
     * Atomically advances last_step. Returns false if another request already used
     * this (or a later) step — that makes TOTP codes strictly single-use even under races.
     */
    public function advanceTotpStep(string $username, int $step): bool
    {
        $st = $this->pdo->prepare('UPDATE totp_secrets SET last_step = ? WHERE username = ? AND last_step < ?');
        $st->execute([$step, $username, $step]);
        return $st->rowCount() === 1;
    }

    public function deleteTotp(string $username): bool
    {
        $st = $this->pdo->prepare('DELETE FROM totp_secrets WHERE username = ?');
        $st->execute([$username]);
        return $st->rowCount() > 0;
    }

    public function countTotp(): int
    {
        $st = $this->pdo->query('SELECT COUNT(*) FROM totp_secrets');
        return $st === false ? 0 : (int) $st->fetchColumn();
    }

    // ---- Rate limiting -----------------------------------------------------

    public function addRateEvent(string $key, int $ts): void
    {
        $this->pdo->prepare('INSERT INTO rate_events (key, ts) VALUES (?, ?)')->execute([$key, $ts]);
    }

    public function countRateEvents(string $key, int $sinceTs): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE key = ? AND ts > ?');
        $st->execute([$key, $sinceTs]);
        return (int) $st->fetchColumn();
    }

    public function clearRateEvents(string $key): void
    {
        $this->pdo->prepare('DELETE FROM rate_events WHERE key = ?')->execute([$key]);
    }

    public function setLockout(string $key, int $untilTs, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO lockouts (key, until_ts, reason) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET until_ts = excluded.until_ts, reason = excluded.reason'
        )->execute([$key, $untilTs, $reason]);
    }

    public function lockoutUntil(string $key): ?int
    {
        $st = $this->pdo->prepare('SELECT until_ts FROM lockouts WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    public function deleteLockout(string $key): bool
    {
        $st = $this->pdo->prepare('DELETE FROM lockouts WHERE key = ?');
        $st->execute([$key]);
        return $st->rowCount() > 0;
    }

    /** Removes stale rate events (older than 24h) and expired lockouts. */
    public function cleanup(int $nowTs): void
    {
        $this->pdo->prepare('DELETE FROM rate_events WHERE ts < ?')->execute([$nowTs - 86400]);
        $this->pdo->prepare('DELETE FROM lockouts WHERE until_ts < ?')->execute([$nowTs]);
    }

    // ---- Grants (audit of issued access) -------------------------------------

    public function recordGrant(string $username, string $ip, string $grantedAt, string $expiresAt): void
    {
        $this->pdo->prepare('INSERT INTO grants (username, ip, granted_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$username, $ip, $grantedAt, $expiresAt]);
    }

    public function markRevoked(string $username, string $ip, string $at): void
    {
        $this->pdo->prepare(
            'UPDATE grants SET revoked_at = ? WHERE username = ? AND ip = ? AND revoked_at IS NULL AND expires_at > ?'
        )->execute([$at, $username, $ip, $at]);
    }

    /** @return list<array{username: string, ip: string, granted_at: string, expires_at: string, revoked_at: string|null}> */
    public function recentGrants(int $limit): array
    {
        $st = $this->pdo->prepare('SELECT username, ip, granted_at, expires_at, revoked_at FROM grants ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'username' => (string) $r['username'], 'ip' => (string) $r['ip'],
                'granted_at' => (string) $r['granted_at'], 'expires_at' => (string) $r['expires_at'],
                'revoked_at' => $r['revoked_at'] === null ? null : (string) $r['revoked_at'],
            ];
        }
        return $out;
    }

    public function countActiveGrants(string $now): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM grants WHERE revoked_at IS NULL AND expires_at > ?');
        $st->execute([$now]);
        return (int) $st->fetchColumn();
    }

    // ---- Settings (admin panel) ----------------------------------------------

    /** @return array<string, array{value: string, encrypted: bool, updated_at: string, updated_by: string}> */
    public function allSettings(): array
    {
        if (!$this->hasTable('settings')) {
            return [];
        }
        $out = [];
        $st = $this->pdo->query('SELECT key, value, encrypted, updated_at, updated_by FROM settings');
        foreach ($st === false ? [] : $st->fetchAll() as $r) {
            $out[(string) $r['key']] = [
                'value' => (string) $r['value'], 'encrypted' => (int) $r['encrypted'] === 1,
                'updated_at' => (string) $r['updated_at'], 'updated_by' => (string) $r['updated_by'],
            ];
        }
        return $out;
    }

    /** @param array<string, array{value: string, encrypted: bool}> $values */
    public function saveSettings(array $values, string $by, string $at): void
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO settings (key, value, encrypted, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, encrypted = excluded.encrypted,
                 updated_at = excluded.updated_at, updated_by = excluded.updated_by'
            );
            foreach ($values as $key => $v) {
                $st->bindValue(1, $key);
                $st->bindValue(2, $v['value'], $v['encrypted'] ? PDO::PARAM_LOB : PDO::PARAM_STR);
                $st->bindValue(3, $v['encrypted'] ? 1 : 0, PDO::PARAM_INT);
                $st->bindValue(4, $at);
                $st->bindValue(5, $by);
                $st->execute();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteSetting(string $key): bool
    {
        $st = $this->pdo->prepare('DELETE FROM settings WHERE key = ?');
        $st->execute([$key]);
        return $st->rowCount() > 0;
    }

    // ---- Administrators --------------------------------------------------------

    /** @return array{username: string, password_hash: string, last_login_at: string|null, last_login_ip: string|null}|null */
    public function findAdmin(string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT username, password_hash, last_login_at, last_login_ip FROM admins WHERE username = ?');
        $st->execute([$username]);
        $r = $st->fetch();
        if (!is_array($r)) {
            return null;
        }
        return [
            'username' => (string) $r['username'], 'password_hash' => (string) $r['password_hash'],
            'last_login_at' => $r['last_login_at'] === null ? null : (string) $r['last_login_at'],
            'last_login_ip' => $r['last_login_ip'] === null ? null : (string) $r['last_login_ip'],
        ];
    }

    /** @return list<array{username: string, created_at: string, last_login_at: string|null}> */
    public function listAdmins(): array
    {
        $st = $this->pdo->query('SELECT username, created_at, last_login_at FROM admins ORDER BY username');
        $out = [];
        foreach ($st === false ? [] : $st->fetchAll() as $r) {
            $out[] = [
                'username' => (string) $r['username'], 'created_at' => (string) $r['created_at'],
                'last_login_at' => $r['last_login_at'] === null ? null : (string) $r['last_login_at'],
            ];
        }
        return $out;
    }

    public function upsertAdmin(string $username, string $hash, string $at): void
    {
        $this->pdo->prepare(
            'INSERT INTO admins (username, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, updated_at = excluded.updated_at'
        )->execute([$username, $hash, $at, $at]);
    }

    public function deleteAdmin(string $username): bool
    {
        $st = $this->pdo->prepare('DELETE FROM admins WHERE username = ?');
        $st->execute([$username]);
        return $st->rowCount() > 0;
    }

    public function recordAdminLogin(string $username, string $ip, string $at): void
    {
        $this->pdo->prepare('UPDATE admins SET last_login_at = ?, last_login_ip = ? WHERE username = ?')->execute([$at, $ip, $username]);
    }
}
