<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(int $timestamp = 1_800_000_000)
    {
        $this->now = new DateTimeImmutable('@' . $timestamp);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function ts(): int
    {
        return $this->now->getTimestamp();
    }
}
