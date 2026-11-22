<?php

declare(strict_types=1);

namespace VpnGw\Tests;

use OTPHP\TOTP;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use VpnGw\App;
use VpnGw\Config;
use VpnGw\Container;
use VpnGw\Http\Session;
use VpnGw\Log\AuditLogger;
use VpnGw\Mail\Notifier;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FakeAuthenticator;
use VpnGw\Tests\Support\FrozenClock;
use VpnGw\Tests\Support\TestConfig;

/** 0.10: e-mail at the first enrolment, bound to the user; the guide PDF is sent there. */
final class EnrollEmailTest extends TestCase
{
    private const OFFICE_IP = '10.1.2.3';

    /** @var array<string, mixed> */
    private array $store = [];
    private Session $session;
    private FrozenClock $clock;
    private Database $db;
    private SlimApp $app;
    /** @var list<PHPMailer> */
    private array $sent = [];
    private string $pdf;

    protected function setUp(): void
    {
        $this->session = new Session($this->store);
        $this->clock = new FrozenClock(1_800_000_010);
        $this->db = Database::inMemory();
        $this->pdf = sys_get_temp_dir() . '/vpngw-guide-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n% test guide\n");
        $this->boot(['SMTP_HOST' => 'mail.company.ua', 'SMTP_FROM' => 'vpn-gw@company.ua', 'USER_GUIDE_PDF' => $this->pdf]);
    }

    protected function tearDown(): void
    {
        @unlink($this->pdf);
    }

    /** @param array<string, string> $cfg */
    private function boot(array $cfg): void
    {
        $config = TestConfig::make($cfg);
        $audit = AuditLogger::null();
        $this->app = App::create(new Container($config, $this->session, [
            'clock' => $this->clock,
            'db' => $this->db,
            'audit' => $audit,
            'authenticator' => new FakeAuthenticator(),
            'notifier' => new Notifier($config, $audit, function (PHPMailer $m): void {
                $this->sent[] = $m;
            }),
        ]));
    }

    /** @param array<string, string> $body */
    private function request(string $method, array $body = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, 'https://vpn-gw.company.ua/k7f3x/enroll', ['REMOTE_ADDR' => self::OFFICE_IP]);
        if ($method === 'POST') {
            $body['csrf'] = $this->session->csrfToken();
            $req = $req->withParsedBody($body);
        }
        return $this->app->handle($req);
    }

    private function code(): string
    {
        /** @var non-empty-string $secret */
        $secret = (string) $this->session->str('enroll_secret');
        return TOTP::createFromSecret($secret, $this->clock)->at($this->clock->ts());
    }

    private function startEnrolment(): string
    {
        $r = $this->request('POST', ['action' => 'login', 'username' => 'ivanenko', 'password' => 'Correct-Horse-1']);
        self::assertSame(200, $r->getStatusCode());
        return (string) $r->getBody();
    }

    public function testAddressIsPrefilledFromAdAndGuideIsSent(): void
    {
        $html = $this->startEnrolment();
        self::assertStringContainsString('name="email" type="email"', $html);
        self::assertStringContainsString('value="ivanenko@company.ua"', $html, 'AD «mail» as the default');

        $r = $this->request('POST', ['action' => 'confirm', 'code' => $this->code(), 'email' => 'ivan.ivanenko@gmail.com']);
        $body = (string) $r->getBody();
        self::assertStringContainsString('Реєстрацію завершено', $body);
        self::assertStringContainsString('надіслано на ivan.ivanenko@gmail.com', $body);
        self::assertSame('ivan.ivanenko@gmail.com', $this->db->userEmail('ivanenko'), 'bound to the user');

        self::assertCount(1, $this->sent);
        $m = $this->sent[0];
        self::assertSame([['ivan.ivanenko@gmail.com', '']], $m->getToAddresses());
        self::assertSame('VPN-GW: як підключатися до робочого VPN', $m->Subject);
        self::assertStringContainsString('https://vpn-gw.company.ua/k7f3x/', $m->Body);
        $att = $m->getAttachments();
        self::assertCount(1, $att);
        self::assertSame($this->pdf, $att[0][0]);
        self::assertSame('application/pdf', $att[0][4]);
    }

    public function testBadAddressDoesNotUseUpTheCode(): void
    {
        $this->startEnrolment();
        $code = $this->code();
        $r = $this->request('POST', ['action' => 'confirm', 'code' => $code, 'email' => "ivan@company.ua\r\nBcc: x@evil.test"]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Некоректна адреса e-mail', (string) $r->getBody());
        self::assertFalse((new \VpnGw\Auth\TotpService($this->db, new \VpnGw\Security\Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)), $this->clock))->isEnrolled('ivanenko'));

        $r = $this->request('POST', ['action' => 'confirm', 'code' => $code, 'email' => 'ivan@company.ua']);
        self::assertStringContainsString('Реєстрацію завершено', (string) $r->getBody(), 'the same code still works');
        self::assertSame('ivan@company.ua', $this->db->userEmail('ivanenko'));
    }

    public function testEmptyAddressStoresAndSendsNothing(): void
    {
        $this->startEnrolment();
        $r = $this->request('POST', ['action' => 'confirm', 'code' => $this->code(), 'email' => '']);
        self::assertStringContainsString('Реєстрацію завершено', (string) $r->getBody());
        self::assertNull($this->db->userEmail('ivanenko'));
        self::assertSame([], $this->sent);
    }

    public function testSavedAddressIsOfferedOnReEnrolment(): void
    {
        $this->db->setUserEmail('ivanenko', 'saved@company.ua', '2026-11-22T10:00:00+02:00');
        self::assertStringContainsString('value="saved@company.ua"', $this->startEnrolment());
    }

    public function testMailDisabledStillStoresTheAddress(): void
    {
        $this->boot(['USER_GUIDE_PDF' => $this->pdf]);
        $html = $this->startEnrolment();
        self::assertStringContainsString('пошта на порталі не налаштована', $html);
        $r = $this->request('POST', ['action' => 'confirm', 'code' => $this->code(), 'email' => 'ivan@company.ua']);
        self::assertStringContainsString('пошту на порталі не налаштовано', (string) $r->getBody());
        self::assertSame('ivan@company.ua', $this->db->userEmail('ivanenko'));
        self::assertSame([], $this->sent);
    }

    public function testMissingPdfIsReportedNotSent(): void
    {
        @unlink($this->pdf);
        $this->startEnrolment();
        $r = $this->request('POST', ['action' => 'confirm', 'code' => $this->code(), 'email' => 'ivan@company.ua']);
        self::assertStringContainsString('лист з пам’яткою надіслати не вдалося', (string) $r->getBody());
        self::assertSame([], $this->sent);
        self::assertSame('ivan@company.ua', $this->db->userEmail('ivanenko'));
    }

    public function testShippedGuidePdfExists(): void
    {
        $cfg = TestConfig::make();
        $path = dirname(__DIR__) . '/doc/' . Config::USER_GUIDE_FILE;
        self::assertSame($path, $cfg->userGuidePdf());
        self::assertFileExists($path);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path, false, null, 0, 5));
    }
}
