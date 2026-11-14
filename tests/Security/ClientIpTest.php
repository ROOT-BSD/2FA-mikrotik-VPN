<?php

declare(strict_types=1);

namespace VpnGw\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VpnGw\Security\ClientIp;

final class ClientIpTest extends TestCase
{
    private const PROXIES = ['127.0.0.1'];

    public function testUsesRemoteAddrWhenNoProxy(): void
    {
        self::assertSame('203.0.113.45', ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.45'], self::PROXIES));
    }

    public function testIgnoresForwardedForFromUntrustedPeer(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '0.0.0.0/0'];
        self::assertSame('198.51.100.7', ClientIp::resolve($server, self::PROXIES));
    }

    public function testTakesRightmostEntryFromTrustedProxy(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.45'];
        self::assertSame('203.0.113.45', ClientIp::resolve($server, self::PROXIES));
    }

    #[DataProvider('forgedValues')]
    public function testRejectsAnythingButOnePublicIpv4(string $xff): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $xff];
        self::assertNull(ClientIp::resolve($server, self::PROXIES));
    }

    /** @return array<string, array{string}> */
    public static function forgedValues(): array
    {
        return [
            'whole internet' => ['0.0.0.0/0'],
            'cidr' => ['203.0.113.0/24'],
            'range' => ['203.0.113.1-203.0.113.9'],
            'private 10/8' => ['10.0.0.1'],
            'private 192.168' => ['192.168.1.1'],
            'loopback' => ['127.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 public' => ['2001:db8::1'],
            'routeros injection' => ['1.2.3.4 comment=x'],
            'garbage' => ['garbage'],
            'empty last' => ['203.0.113.5,'],
        ];
    }

    public function testRejectsPrivateRemoteAddr(): void
    {
        self::assertNull(ClientIp::resolve(['REMOTE_ADDR' => '10.1.2.3'], []));
        self::assertNull(ClientIp::resolve([], []));
    }
}
