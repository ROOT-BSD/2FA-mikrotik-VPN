<?php

declare(strict_types=1);

namespace VpnGw\Tests\Security;

use PHPUnit\Framework\TestCase;
use VpnGw\Security\RateLimiter;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FrozenClock;

final class RateLimiterTest extends TestCase
{
    private FrozenClock $clock;
    private Database $db;
    private RateLimiter $rl;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->db = Database::inMemory();
        $this->rl = new RateLimiter($this->db, $this->clock);
    }

    public function testLimitReachedOnFifthAttempt(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            self::assertFalse($this->rl->hit('ip:203.0.113.1', 5, 900), "attempt $i");
        }
        self::assertTrue($this->rl->hit('ip:203.0.113.1', 5, 900));
    }

    public function testOldEventsLeaveTheWindow(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->rl->hit('user:a', 5, 900);
        }
        $this->clock->advance(901);
        self::assertFalse($this->rl->hit('user:a', 5, 900));
    }

    public function testLockExpires(): void
    {
        $this->rl->lock('ip:203.0.113.1', 900, 'login');
        self::assertTrue($this->rl->isLocked('ip:203.0.113.1'));
        self::assertFalse($this->rl->isLocked('ip:203.0.113.2'));
        $this->clock->advance(901);
        self::assertFalse($this->rl->isLocked('ip:203.0.113.1'));
    }

    public function testLockSurvivesNewSessionBecauseItIsServerSide(): void
    {
        $this->rl->lock('user:ivanenko', 900, 'mfa');
        // A brand-new limiter instance (new request / new session) sees the same lock.
        $other = new RateLimiter($this->db, $this->clock);
        self::assertTrue($other->isLocked('user:ivanenko'));
    }

    public function testClear(): void
    {
        $this->rl->hit('user:a', 1, 900);
        $this->rl->lock('user:a', 900, 'x');
        $this->rl->clear('user:a');
        self::assertFalse($this->rl->isLocked('user:a'));
        self::assertFalse($this->rl->hit('user:a', 2, 900));
    }

    public function testCleanupRemovesStaleData(): void
    {
        $this->rl->hit('k', 10, 900);
        $this->rl->lock('k', 10, 'x');
        $this->clock->advance(90_000);
        $this->db->cleanup($this->clock->ts());
        self::assertSame(0, $this->db->countRateEvents('k', 0));
        self::assertNull($this->db->lockoutUntil('k'));
    }
}
