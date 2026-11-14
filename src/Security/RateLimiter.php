<?php

declare(strict_types=1);

namespace VpnGw\Security;

use Psr\Clock\ClockInterface;
use VpnGw\Storage\Database;

/**
 * Server-side attempt counters and lockouts. Unlike counters in $_SESSION, these
 * cannot be reset by simply starting a new session.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Records one event; returns true when the limit within the window is reached. */
    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        $now = $this->now();
        $this->db->addRateEvent($key, $now);
        return $this->db->countRateEvents($key, $now - $windowSeconds) >= $limit;
    }

    public function isLocked(string $key): bool
    {
        $until = $this->db->lockoutUntil($key);
        return $until !== null && $until > $this->now();
    }

    public function lock(string $key, int $seconds, string $reason): void
    {
        $this->db->setLockout($key, $this->now() + $seconds, $reason);
    }

    public function clear(string $key): void
    {
        $this->db->clearRateEvents($key);
        $this->db->deleteLockout($key);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
