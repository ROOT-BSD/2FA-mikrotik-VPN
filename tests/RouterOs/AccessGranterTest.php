<?php

declare(strict_types=1);

namespace VpnGw\Tests\RouterOs;

use PHPUnit\Framework\TestCase;
use VpnGw\Log\AuditLogger;
use VpnGw\RouterOs\AccessGranter;
use VpnGw\RouterOs\ApiSslClientV6;
use VpnGw\RouterOs\RouterOsException;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FakeRouter;
use VpnGw\Tests\Support\FrozenClock;

final class AccessGranterTest extends TestCase
{
    private FakeRouter $v7;
    private FakeRouter $v6;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->v7 = new FakeRouter('core');
        $this->v6 = new FakeRouter('branch');
        $this->clock = new FrozenClock();
    }

    private function granter(): AccessGranter
    {
        return new AccessGranter(
            [$this->v7, $this->v6], 'Allowed_VPN_Users', '10:00:00', 36000,
            sys_get_temp_dir() . '/vpngw-test-locks', Database::inMemory(), AuditLogger::null(), $this->clock,
        );
    }

    public function testGrantAddsToEveryRouterAndReturnsExpiry(): void
    {
        $until = $this->granter()->grant('ivanenko', '203.0.113.45');
        self::assertSame($this->clock->ts() + 36000, $until->getTimestamp());
        foreach ([$this->v7, $this->v6] as $r) {
            self::assertCount(1, $r->entries);
            $e = array_values($r->entries)[0];
            self::assertSame('203.0.113.45', $e['address']);
            self::assertSame('Allowed_VPN_Users', $e['list']);
            self::assertSame('10:00:00', $e['timeout']);
            self::assertSame('User: ivanenko via VPN-2FA', $e['comment']);
        }
    }

    public function testRepeatedGrantUpdatesInsteadOfDuplicating(): void
    {
        $g = $this->granter();
        $g->grant('ivanenko', '203.0.113.45');
        $g->grant('ivanenko', '203.0.113.45');
        self::assertCount(1, $this->v7->entries);
        self::assertContains('update', $this->v7->calls);
    }

    public function testRevokeRemovesEntryAndDropsSessions(): void
    {
        $g = $this->granter();
        $g->grant('ivanenko', '203.0.113.45');
        $g->revoke('ivanenko', '203.0.113.45');
        self::assertSame([], $this->v7->entries);
        self::assertSame([['ivanenko', '203.0.113.45']], $this->v6->droppedSessions);
    }

    public function testFailureOnOneRouterIsReportedButOthersAreStillProcessed(): void
    {
        $this->v7->fail = true;
        try {
            $this->granter()->grant('ivanenko', '203.0.113.45');
            self::fail('Expected RouterOsException');
        } catch (RouterOsException $e) {
            self::assertStringContainsString('core', $e->getMessage());
        }
        self::assertCount(1, $this->v6->entries);
    }

    public function testRefusesPrivateOrMalformedAddresses(): void
    {
        foreach (['10.0.0.1', '0.0.0.0/0', '1.2.3.4 comment=x', '::1'] as $ip) {
            try {
                $this->granter()->grant('ivanenko', $ip);
                self::fail("Accepted $ip");
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->v7->calls);
            }
        }
    }

    public function testCommentKeepsOnlySafeCharacters(): void
    {
        self::assertSame('User: useripfirewallfilterremovefind via VPN-2FA', AccessGranter::comment('user"; /ip firewall filter remove [find]'));
    }

    public function testApiSslQueryUsesSeparateWords(): void
    {
        $q = ApiSslClientV6::query('/ip/firewall/address-list/add', set: ['address' => "1.2.3.4\n/system/reset", 'comment' => 'x']);
        self::assertSame('/ip/firewall/address-list/add', $q->getEndpoint());
        self::assertSame(['=address=1.2.3.4/system/reset', '=comment=x'], $q->getAttributes());
        $find = ApiSslClientV6::query('/ip/firewall/address-list/print', where: ['list' => 'L', 'address' => '1.2.3.4']);
        self::assertSame(['?list=L', '?address=1.2.3.4'], $find->getAttributes());
    }

    /** 0.7: each router may use its own address-list. */
    public function testPerRouterAddressList(): void
    {
        $g = new AccessGranter(
            [$this->v7, $this->v6], 'Allowed_VPN_Users', '10:00:00', 36000,
            sys_get_temp_dir() . '/vpngw-test-locks', Database::inMemory(), AuditLogger::null(), $this->clock,
            [], ['core' => 'VPN_core'],
        );
        $g->grant('ivanenko', '203.0.113.45');
        self::assertSame('VPN_core', array_values($this->v7->entries)[0]['list']);
        self::assertSame('Allowed_VPN_Users', array_values($this->v6->entries)[0]['list'], 'router without own list uses MT_LIST');
        $g->grant('ivanenko', '203.0.113.45');
        self::assertCount(1, $this->v7->entries, 'refresh finds the entry in the router\'s own list');
        $g->revoke('ivanenko', '203.0.113.45');
        self::assertSame([], $this->v7->entries);
        self::assertSame([], $this->v6->entries);
    }
}
