<?php

declare(strict_types=1);

namespace VpnGw\Tests\RouterOs;

use PHPUnit\Framework\TestCase;
use VpnGw\RouterOs\AddressLists;
use VpnGw\RouterOs\RouterOsException;
use VpnGw\RouterOs\SshClient;
use VpnGw\Tests\Support\FakeRouter;
use VpnGw\Tests\Support\FakeSshSession;

/** 0.6: reading address-list names from routers for the panel. */
final class AddressListsTest extends TestCase
{
    public function testBuildMergesEntriesAndRuleReferences(): void
    {
        $lists = AddressLists::build(
            ['blacklist' => 120, 'Allowed_VPN_Users' => 3, 'bad name with spaces' => 2],
            ['Allowed_VPN_Users', '!Allowed_VPN_Users', 'VPN_2FA', '', 'x"; /system reset'],
        );
        self::assertSame([
            'Allowed_VPN_Users' => ['entries' => 3, 'rules' => 2],
            'blacklist' => ['entries' => 120, 'rules' => 0],
            'VPN_2FA' => ['entries' => 0, 'rules' => 1],
        ], $lists, 'negated refs count, unusable names dropped, natural case-insensitive order');
    }

    public function testRestAndApiRowHelpers(): void
    {
        self::assertSame(['a' => 2, 'b' => 1], AddressLists::countEntries([['list' => 'a'], ['list' => 'b'], ['list' => 'a'], ['address' => 'x'], 'ret' => 'x']));
        self::assertSame(['a', 'b', 'c'], AddressLists::ruleRefs([
            ['src-address-list' => 'a'], ['dst-address-list' => 'b', 'src-address-list' => ''], ['src-address-list' => 'c'], [],
        ]));
    }

    public function testSshParsesScriptOutput(): void
    {
        $s = new FakeSshSession();
        $c = new SshClient('core', static fn () => $s, 'vpn_portal', 'pw', '', '', 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        $s->answers = ["E 3 Allowed_VPN_Users\r\nE 120 blacklist\r\nR Allowed_VPN_Users\r\nR \r\nR !blacklist\r\nR VPN_2FA\r\nR \r\nEND\r\n"];
        self::assertSame([
            'Allowed_VPN_Users' => ['entries' => 3, 'rules' => 1],
            'blacklist' => ['entries' => 120, 'rules' => 1],
            'VPN_2FA' => ['entries' => 0, 'rules' => 1],
        ], $c->addressLists());
        self::assertSame([SshClient::LISTS_SCRIPT], $s->commands, 'fixed script, no values from outside');
    }

    public function testSshWithoutEndMarkerFails(): void
    {
        $s = new FakeSshSession();
        $c = new SshClient('core', static fn () => $s, 'vpn_portal', 'pw', '', '', 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        $s->answers = ["expected end of command (line 1 column 5)\r\n"];
        $this->expectException(RouterOsException::class);
        $c->addressLists();
    }

    public function testFakeRouter(): void
    {
        $r = new FakeRouter('core');
        $r->addEntry('Allowed_VPN_Users', '203.0.113.1', '10:00:00', 'x');
        $r->ruleLists = ['Allowed_VPN_Users', 'VPN_2FA'];
        self::assertSame(['Allowed_VPN_Users' => ['entries' => 1, 'rules' => 1], 'VPN_2FA' => ['entries' => 0, 'rules' => 1]], $r->addressLists());
    }
}
