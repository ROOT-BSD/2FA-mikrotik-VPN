<?php

declare(strict_types=1);

namespace VpnGw\Tests\Security;

use PHPUnit\Framework\TestCase;
use VpnGw\Security\Cidr;

final class CidrTest extends TestCase
{
    public function testContains(): void
    {
        self::assertTrue(Cidr::contains('10.0.0.0/8', '10.20.30.40'));
        self::assertFalse(Cidr::contains('10.0.0.0/8', '11.0.0.1'));
        self::assertTrue(Cidr::contains('192.168.5.0/24', '192.168.5.255'));
        self::assertFalse(Cidr::contains('192.168.5.0/24', '192.168.6.1'));
        self::assertTrue(Cidr::contains('203.0.113.7/32', '203.0.113.7'));
        self::assertTrue(Cidr::contains('0.0.0.0/0', '8.8.8.8'));
    }

    public function testRejectsInvalidInput(): void
    {
        self::assertFalse(Cidr::isValid('10.0.0.0'));
        self::assertFalse(Cidr::isValid('10.0.0.0/33'));
        self::assertFalse(Cidr::isValid('300.0.0.0/8'));
        self::assertFalse(Cidr::contains('10.0.0.0/8', '::1'));
        self::assertFalse(Cidr::contains('10.0.0.0/8', 'abc'));
    }

    public function testContainsAny(): void
    {
        self::assertTrue(Cidr::containsAny(['172.16.0.0/12', '10.0.0.0/8'], '10.1.1.1'));
        self::assertFalse(Cidr::containsAny([], '10.1.1.1'));
    }
}
