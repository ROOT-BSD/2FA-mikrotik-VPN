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

    public function migrate(): void
    {
        $sql = file_get_contents(__DIR__ . '/migrations/001_init.sql');
        if ($sql === false) {
            throw new \RuntimeException('Migration file not found');
        }
        $this->pdo->exec($sql);
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
}
