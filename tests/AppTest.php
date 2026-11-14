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
use VpnGw\Container;
use VpnGw\Http\Session;
use VpnGw\Log\AuditLogger;
use VpnGw\RouterOs\AccessGranter;
use VpnGw\Security\Crypto;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FakeAuthenticator;
use VpnGw\Tests\Support\FakeRouter;
use VpnGw\Tests\Support\FrozenClock;
use VpnGw\Tests\Support\TestConfig;

/** End-to-end HTTP flows through Slim with fake AD and fake routers. */
final class AppTest extends TestCase
{
    private const PUBLIC_IP = '203.0.113.45';
    private const OFFICE_IP = '10.5.6.7';

    /** @var array<string, mixed> */
    private array $store = [];
    private Session $session;
    private FrozenClock $clock;
    private Database $db;
    private FakeRouter $router;
    private FakeAuthenticator $ad;
    /** @var SlimApp<\Psr\Container\ContainerInterface|null> */
    private SlimApp $app;

    protected function setUp(): void
    {
        $this->store = [];
        $this->session = new Session($this->store);
        $this->clock = new FrozenClock(1_800_000_010);
        $this->db = Database::inMemory();
        $this->router = new FakeRouter('core');
        $this->ad = new FakeAuthenticator();
        $cfg = TestConfig::make();
        $audit = AuditLogger::null();
        $this->app = App::create(new Container($cfg, $this->session, [
            'clock' => $this->clock,
            'db' => $this->db,
            'audit' => $audit,
            'authenticator' => $this->ad,
            'granter' => new AccessGranter([$this->router], 'Allowed_VPN_Users', '10:00:00', 36000,
                sys_get_temp_dir() . '/vpngw-test-locks', $this->db, $audit, $this->clock),
        ]));
    }

    /** @param array<string, string> $body */
    private function request(string $method, string $path, array $body = [], string $ip = self::PUBLIC_IP, bool $csrf = true): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, 'https://vpn-gw.company.ua' . $path, ['REMOTE_ADDR' => $ip]);
        if ($method === 'POST') {
            if ($csrf) {
                $body['csrf'] = $this->session->csrfToken();
            }
            $req = $req->withParsedBody($body);
        }
        return $this->app->handle($req);
    }

    private function enrollTotp(): string
    {
        $svc = new TotpService($this->db, new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)), $this->clock);
        $secret = $svc->newSecret('ivanenko')['secret'];
        self::assertTrue($svc->confirmEnrollment('ivanenko', $secret, $this->code($secret), 'self'));
        $this->clock->advance(30);
        return $secret;
    }

    private function code(string $secret): string
    {
        /** @var non-empty-string $secret */
        return TOTP::createFromSecret($secret, $this->clock)->at($this->clock->ts());
    }

    public function testEverythingOutsideBasePathIs404(): void
    {
        self::assertSame(404, $this->request('GET', '/')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/k7f3x/admin')->getStatusCode());
        self::assertSame(404, $this->request('PUT', '/k7f3x/login')->getStatusCode(), '405 is masked as 404');
    }

    public function testLoginPageHasSecurityHeaders(): void
    {
        $r = $this->request('GET', '/k7f3x/');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString("frame-ancestors 'none'", $r->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        self::assertSame('DENY', $r->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString('name="csrf"', (string) $r->getBody());
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $r = $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1'], csrf: false);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(0, $this->ad->calls);
    }

    public function testFullFlowGrantsAccessAndRevokes(): void
    {
        $secret = $this->enrollTotp();

        $r = $this->request('POST', '/k7f3x/login', ['username' => 'wrong', 'password' => 'Correct-Horse-1']);
        self::assertSame(401, $r->getStatusCode());

        $r = $this->request('POST', '/k7f3x/login', ['username' => ' IvanEnko ', 'password' => 'Correct-Horse-1']);
        self::assertSame(303, $r->getStatusCode(), 'login is trimmed and lower-cased');
        self::assertSame('/k7f3x/verify', $r->getHeaderLine('Location'));
        self::assertSame(200, $this->request('GET', '/k7f3x/verify')->getStatusCode());

        $r = $this->request('POST', '/k7f3x/verify', ['code' => $this->code($secret)]);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/k7f3x/result', $r->getHeaderLine('Location'));
        self::assertCount(1, $this->router->entries);
        self::assertSame(self::PUBLIC_IP, array_values($this->router->entries)[0]['address']);

        $page = (string) $this->request('GET', '/k7f3x/result')->getBody();
        self::assertStringContainsString('Доступ дозволено', $page);
        self::assertStringContainsString(self::PUBLIC_IP, $page);

        $r = $this->request('POST', '/k7f3x/revoke');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame([], $this->router->entries);
        self::assertSame([['ivanenko', self::PUBLIC_IP]], $this->router->droppedSessions);
    }

    public function testThreeWrongCodesLockIpAndUser(): void
    {
        $this->enrollTotp();
        $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        self::assertSame(401, $this->request('POST', '/k7f3x/verify', ['code' => '000000'])->getStatusCode());
        self::assertSame(401, $this->request('POST', '/k7f3x/verify', ['code' => '000001'])->getStatusCode());
        self::assertSame(429, $this->request('POST', '/k7f3x/verify', ['code' => '000002'])->getStatusCode());
        self::assertSame([], $this->router->entries);

        // New session, correct password: still blocked, and AD is not even asked.
        $before = $this->ad->calls;
        $r = $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        self::assertSame(429, $r->getStatusCode());
        self::assertSame($before, $this->ad->calls);
    }

    public function testPasswordGuessingLocksUserBeforeAdLockout(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => "wrong$i"]);
        }
        $calls = $this->ad->calls;
        $r = $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        self::assertSame(429, $r->getStatusCode());
        self::assertSame($calls, $this->ad->calls, 'locked user must not reach AD');
    }

    public function testSessionIsBoundToClientIp(): void
    {
        $secret = $this->enrollTotp();
        $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        $r = $this->request('POST', '/k7f3x/verify', ['code' => $this->code($secret)], '198.51.100.9');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/k7f3x/', $r->getHeaderLine('Location'));
        self::assertSame([], $this->router->entries);
    }

    public function testMfaStepExpires(): void
    {
        $secret = $this->enrollTotp();
        $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        $this->clock->advance(301);
        $r = $this->request('POST', '/k7f3x/verify', ['code' => $this->code($secret)]);
        self::assertSame('/k7f3x/', $r->getHeaderLine('Location'));
        self::assertSame([], $this->router->entries);
    }

    public function testNotEnrolledUserIsToldToEnrollInOffice(): void
    {
        $r = $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        self::assertSame(403, $r->getStatusCode());
        self::assertStringContainsString('Автентифікатор не зареєстровано', (string) $r->getBody());
    }

    public function testEnrollIsInvisibleFromInternet(): void
    {
        self::assertSame(404, $this->request('GET', '/k7f3x/enroll')->getStatusCode());
        self::assertSame(404, $this->request('POST', '/k7f3x/enroll', ['action' => 'login'], csrf: false)->getStatusCode(), 'no CSRF 403 leak');
    }

    public function testEnrollFromOfficeThenLoginFromHome(): void
    {
        self::assertSame(200, $this->request('GET', '/k7f3x/enroll', [], self::OFFICE_IP)->getStatusCode());
        $r = $this->request('POST', '/k7f3x/enroll', ['action' => 'login', 'username' => 'ivanenko', 'password' => 'Correct-Horse-1'], self::OFFICE_IP);
        $html = (string) $r->getBody();
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('data:image/svg+xml;base64,', $html);

        $secret = (string) $this->session->str('enroll_secret');
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $r = $this->request('POST', '/k7f3x/enroll', ['action' => 'confirm', 'code' => $this->code($secret)], self::OFFICE_IP);
        self::assertStringContainsString('Реєстрацію завершено', (string) $r->getBody());
        self::assertNull($this->session->str('enroll_secret'), 'secret removed from session');

        $this->clock->advance(30);
        $this->request('POST', '/k7f3x/login', ['username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        $r = $this->request('POST', '/k7f3x/verify', ['code' => $this->code($secret)]);
        self::assertSame('/k7f3x/result', $r->getHeaderLine('Location'));
    }

    public function testOutputIsEscaped(): void
    {
        $r = $this->request('POST', '/k7f3x/login', ['username' => '<script>alert(1)</script>', 'password' => 'x']);
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $r->getBody());
    }
}
