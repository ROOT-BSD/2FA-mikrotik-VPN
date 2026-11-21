<?php

declare(strict_types=1);

namespace VpnGw\Tests;

use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use VpnGw\App;
use VpnGw\Auth\TotpService;
use VpnGw\ConfigException;
use VpnGw\Container;
use VpnGw\Http\Session;
use VpnGw\Log\AuditLogger;
use VpnGw\RouterOs\AccessGranter;
use VpnGw\Security\ClientIp;
use VpnGw\Security\Crypto;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FakeAuthenticator;
use VpnGw\Tests\Support\FakeRouter;
use VpnGw\Tests\Support\FrozenClock;
use VpnGw\Tests\Support\TestConfig;

/** PRIVATE_CLIENT_NETWORKS: logins from an allowed private network (e.g. 172.20.2.0/23). */
final class PrivateClientNetworkTest extends TestCase
{
    private const LAN = '172.20.2.0/23';

    /** @var array<string, mixed> */
    private array $store = [];
    private Session $session;
    private FrozenClock $clock;
    private Database $db;
    private FakeRouter $router;
    /** @var SlimApp<\Psr\Container\ContainerInterface|null> */
    private SlimApp $app;

    private function boot(string $networks): void
    {
        $this->store = [];
        $this->session = new Session($this->store);
        $this->clock = new FrozenClock(1_800_000_010);
        $this->db = Database::inMemory();
        $this->router = new FakeRouter('core');
        $cfg = TestConfig::make(['PRIVATE_CLIENT_NETWORKS' => $networks]);
        $audit = AuditLogger::null();
        $this->app = App::create(new Container($cfg, $this->session, [
            'clock' => $this->clock, 'db' => $this->db, 'audit' => $audit, 'authenticator' => new FakeAuthenticator(),
            'granter' => new AccessGranter([$this->router], 'Allowed_VPN_Users', '10:00:00', 36000,
                sys_get_temp_dir() . '/vpngw-test-locks', $this->db, $audit, $this->clock, $cfg->list('PRIVATE_CLIENT_NETWORKS')),
        ]));
        $svc = new TotpService($this->db, new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)), $this->clock);
        $secret = $svc->newSecret('ivanenko')['secret'];
        $svc->confirmEnrollment('ivanenko', $secret, $this->code($secret), 'self');
        $this->clock->advance(30);
        $this->store['__secret'] = $secret;
    }

    private function code(string $secret): string
    {
        /** @var non-empty-string $secret */
        return TOTP::createFromSecret($secret, $this->clock)->at($this->clock->ts());
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body, string $ip): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://vpn-gw.company.ua/k7f3x' . $path, ['REMOTE_ADDR' => $ip]);
        return $this->app->handle($req->withParsedBody($body + ['csrf' => $this->session->csrfToken()]));
    }

    public function testPrivateAddressIsRefusedByDefault(): void
    {
        $this->boot('');
        $r = $this->post('/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1'], '172.20.2.29');
        self::assertSame(400, $r->getStatusCode(), 'bad_ip');
    }

    public function testLoginFromAllowedPrivateNetwork(): void
    {
        $this->boot(self::LAN);
        $secret = (string) $this->store['__secret'];
        $r = $this->post('/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1'], '172.20.2.29');
        self::assertSame(303, $r->getStatusCode());
        $r = $this->post('/verify', ['code' => $this->code($secret)], '172.20.2.29');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('172.20.2.29', array_values($this->router->entries)[0]['address']);
    }

    public function testOtherPrivateNetworksStillRefused(): void
    {
        $this->boot(self::LAN);
        foreach (['172.20.4.1', '10.0.0.5', '192.168.1.10'] as $ip) {
            $r = $this->post('/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1'], $ip);
            self::assertSame(400, $r->getStatusCode(), $ip);
        }
    }

    public function testAcceptableAddresses(): void
    {
        self::assertTrue(ClientIp::isAcceptable('203.0.113.45'));
        self::assertFalse(ClientIp::isAcceptable('172.20.2.29'));
        self::assertTrue(ClientIp::isAcceptable('172.20.3.254', [self::LAN]), '/23 covers .2 and .3');
        self::assertFalse(ClientIp::isAcceptable('172.20.4.1', [self::LAN]));
        self::assertFalse(ClientIp::isAcceptable('127.0.0.1', ['127.0.0.0/8']), 'loopback never');
        self::assertFalse(ClientIp::isAcceptable('0.0.0.0', ['0.0.0.0/8']));
        self::assertFalse(ClientIp::isAcceptable('172.20.2.0/23', [self::LAN]), 'a network is not an address');
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '172.20.2.29'];
        self::assertSame('172.20.2.29', ClientIp::resolve($server, ['127.0.0.1'], [self::LAN]), 'via trusted proxy');
    }

    public function testInvalidNetworksAreRejected(): void
    {
        foreach (['172.20.2.0', '172.20.2.0/33', '0.0.0.0/0', '10.0.0.0/4', 'lan'] as $bad) {
            try {
                TestConfig::make(['PRIVATE_CLIENT_NETWORKS' => $bad]);
                self::fail("accepted $bad");
            } catch (ConfigException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['172.20.2.0/23', '10.10.0.0/16'], TestConfig::make(['PRIVATE_CLIENT_NETWORKS' => '172.20.2.0/23, 10.10.0.0/16'])->list('PRIVATE_CLIENT_NETWORKS'));
    }
}
