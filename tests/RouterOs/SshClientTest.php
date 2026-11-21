<?php

declare(strict_types=1);

namespace VpnGw\Tests\RouterOs;

use PHPUnit\Framework\TestCase;
use VpnGw\RouterOs\PhpseclibSession;
use VpnGw\RouterOs\RouterOsException;
use VpnGw\RouterOs\RouterOsFactory;
use VpnGw\RouterOs\SshClient;
use VpnGw\Tests\Support\FakeSshSession;
use VpnGw\Tests\Support\TestConfig;

final class SshClientTest extends TestCase
{
    private const FP = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const OTHER_FP = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    private function client(FakeSshSession $s, ?string $pinned = self::FP, bool $tofu = false, string $user = 'vpn_portal'): SshClient
    {
        return new SshClient('core', static fn () => $s, $user, 'pw', '', '', $pinned, $tofu);
    }

    public function testCommandsAreBuiltFromWhitelistedValues(): void
    {
        $s = new FakeSshSession();
        $c = $this->client($s);
        $c->addEntry('Allowed_VPN_Users', '203.0.113.45', '10:00:00', 'User: ivanenko via VPN-2FA');
        $s->answers = ["*1A\r\n"];
        self::assertSame('*1A', $c->findEntry('Allowed_VPN_Users', '203.0.113.45'));
        $c->updateEntry('*1A', '10:00:00', 'User: ivanenko via VPN-2FA');
        $c->removeEntry('*1A');
        $c->dropSessions('ivanenko', '203.0.113.45');
        self::assertSame([
            '/ip firewall address-list add list="Allowed_VPN_Users" address="203.0.113.45" timeout=10:00:00 comment="User: ivanenko via VPN-2FA"',
            ':put [:tostr [/ip firewall address-list find list="Allowed_VPN_Users" address="203.0.113.45"]]',
            '/ip firewall address-list set *1A timeout=10:00:00 comment="User: ivanenko via VPN-2FA"',
            '/ip firewall address-list remove *1A',
            '/ppp active remove [find name="ivanenko"]',
            '/ip ipsec active-peers remove [find remote-address=203.0.113.45]',
        ], $s->commands);
        self::assertSame(['vpn_portal+cet', 'pw', '', ''], $s->loginWith, 'plain output mode suffix');
        self::assertCount(1, array_filter([$s->connected]), 'one session reused for all commands');
    }

    public function testFindParsesEmptyAndMultipleIds(): void
    {
        $s = new FakeSshSession();
        $c = $this->client($s);
        $s->answers = ["\r\n", "*1;*2F\r\n", "bad command name find (line 1 column 30)\r\n"];
        self::assertNull($c->findEntry('L', '203.0.113.45'));
        self::assertSame('*1', $c->findEntry('L', '203.0.113.45'));
        $this->expectException(RouterOsException::class);
        $c->findEntry('L', '203.0.113.45');
    }

    /** @return array<string, array{\Closure(SshClient): void}> */
    public static function injections(): array
    {
        return [
            'quote in list' => [fn (SshClient $c) => $c->addEntry('L" ; /system reset-configuration', '203.0.113.45', '10:00:00', 'x')],
            'command substitution in comment' => [fn (SshClient $c) => $c->addEntry('L', '203.0.113.45', '10:00:00', 'x [/user add name=evil]')],
            'dollar in comment' => [fn (SshClient $c) => $c->addEntry('L', '203.0.113.45', '10:00:00', '$x')],
            'ip with command' => [fn (SshClient $c) => $c->findEntry('L', '203.0.113.45 ; /quit')],
            'ipv6' => [fn (SshClient $c) => $c->findEntry('L', '2001:db8::1')],
            'timeout' => [fn (SshClient $c) => $c->addEntry('L', '203.0.113.45', '10h; /quit', 'x')],
            'id' => [fn (SshClient $c) => $c->removeEntry('*1 ; /quit')],
            'user' => [fn (SshClient $c) => $c->dropSessions('a"b', '203.0.113.45')],
            'newline' => [fn (SshClient $c) => $c->addEntry('L', '203.0.113.45', '10:00:00', "x\n/quit")],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('injections')]
    public function testUnsafeValuesAreRefusedNotEscaped(\Closure $call): void
    {
        $s = new FakeSshSession();
        try {
            $call($this->client($s));
            self::fail('expected refusal');
        } catch (RouterOsException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }
        self::assertSame([], $s->commands, 'nothing was sent to the router');
    }

    public function testErrorOutputBecomesException(): void
    {
        $s = new FakeSshSession();
        $s->answers = ["failure: already have such entry\r\n"];
        $this->expectExceptionMessage('core: SSH: failure: already have such entry');
        $this->client($s)->addEntry('L', '203.0.113.45', '10:00:00', 'x');
    }

    public function testUnpinnedHostKeyIsRefusedForThePortal(): void
    {
        $s = new FakeSshSession();
        try {
            $this->client($s, null)->findEntry('L', '203.0.113.45');
            self::fail('expected refusal');
        } catch (RouterOsException $e) {
            self::assertStringContainsString('not confirmed', $e->getMessage());
        }
        self::assertNull($s->loginWith, 'no password was sent to an unknown host');
    }

    public function testChangedHostKeyIsRefusedBeforeLogin(): void
    {
        $s = new FakeSshSession(self::OTHER_FP);
        try {
            $this->client($s)->findEntry('L', '203.0.113.45');
            self::fail('expected refusal');
        } catch (RouterOsException $e) {
            self::assertStringContainsString('CHANGED', $e->getMessage());
        }
        self::assertNull($s->loginWith);
    }

    public function testTrustOnFirstUseReportsFingerprint(): void
    {
        $s = new FakeSshSession(self::OTHER_FP);
        $c = $this->client($s, null, true);
        self::assertNull($c->findEntry('L', '203.0.113.45'));
        self::assertSame(self::OTHER_FP, $c->seenFingerprint());
    }

    public function testLoginFailure(): void
    {
        $s = new FakeSshSession(self::FP, false);
        $this->expectExceptionMessage('SSH login failed');
        $this->client($s)->findEntry('L', '203.0.113.45');
    }

    public function testExplicitLoginSuffixIsKept(): void
    {
        $s = new FakeSshSession();
        $this->client($s, self::FP, false, 'portal+ct')->removeEntry('*1');
        self::assertSame('portal+ct', $s->loginWith[0] ?? null);
    }

    public function testFingerprintMatchesOpenSsh(): void
    {
        // value printed by `ssh-keygen -lf` for this public key
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIQc+3HVvIsre6d7wisa+rMBldIHPtRljTu/FkqxQ5Jn';
        self::assertSame('SHA256:SSG2GjPi463apjJdeW28D8k+dfTM5dJQa47OcSe5BN8', PhpseclibSession::fingerprint($key));
        self::assertSame(PhpseclibSession::fingerprint($key), PhpseclibSession::fingerprint($key . ' comment'));
    }

    public function testFactoryChoosesTransportPerRouter(): void
    {
        $cfg = TestConfig::make([
            'ROUTERS' => '[{"name":"core","host":"10.0.10.1","version":7,"port":8443},{"name":"old","host":"10.0.30.1","version":6,"port":22,"transport":"ssh"}]',
            'MT_SSH_USER' => 'vpn_portal', 'MT_SSH_PASS' => 'x',
        ]);
        $clients = RouterOsFactory::fromConfig($cfg);
        self::assertInstanceOf(\VpnGw\RouterOs\RestClientV7::class, $clients[0]);
        self::assertInstanceOf(SshClient::class, $clients[1]);
    }
}
