<?php

declare(strict_types=1);

namespace VpnGw\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use VpnGw\Admin\AdminAuth;
use VpnGw\App;
use VpnGw\Container;
use VpnGw\Http\Session;
use VpnGw\Log\AuditLogger;
use VpnGw\Security\Crypto;
use VpnGw\Settings\SettingsStore;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FakeAuthenticator;
use VpnGw\Tests\Support\FakeDiagnostics;
use VpnGw\Tests\Support\FrozenClock;
use VpnGw\Tests\Support\TempDir;
use VpnGw\Tests\Support\TestConfig;
use VpnGw\Version;

/** End-to-end tests of the admin panel through Slim. */
final class AdminPanelTest extends TestCase
{
    private const OFFICE_IP = '10.1.2.3';
    private const OTHER_OFFICE_IP = '10.9.9.9';
    private const PUBLIC_IP = '203.0.113.45';
    private const PASSWORD = 'Kyiv-Pass-2026!x';

    /** @var array<string, mixed> */
    private array $store = [];
    private Session $session;
    private FrozenClock $clock;
    private Database $db;
    private FakeDiagnostics $diag;
    private string $tmp;
    /** @var array<string, string> */
    private array $cfgOverride = [];
    /** @var SlimApp<\Psr\Container\ContainerInterface|null> */
    private SlimApp $app;

    protected function setUp(): void
    {
        $this->tmp = TempDir::make();
        foreach (['data/spool', 'agent/status', 'backups', 'logs'] as $d) {
            mkdir($this->tmp . '/' . $d, 0700, true);
        }
        $this->store = [];
        $this->session = new Session($this->store);
        $this->clock = new FrozenClock(1_800_000_000);
        $this->db = Database::inMemory();
        $this->diag = new FakeDiagnostics();
        $this->cfgOverride = [
            'DATA_DIR' => $this->tmp . '/data',
            'AGENT_DIR' => $this->tmp . '/agent',
            'BACKUP_DIR' => $this->tmp . '/backups',
            'LOG_PATH' => $this->tmp . '/logs/audit.log',
            'BACKUP_PASSPHRASE' => 'backup-passphrase-1',
        ];
        (new AdminAuth($this->db, $this->clock))->setPassword('admin', self::PASSWORD);
        $this->boot();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /** (Re)creates the app, e.g. after settings were saved — like the next HTTP request would. */
    private function boot(): void
    {
        $panel = $this->settings()->values();
        $cfg = \VpnGw\Config::fromArray($this->cfgOverride + TestConfig::values(), $panel);
        $this->app = App::create(new Container($cfg, $this->session, [
            'clock' => $this->clock,
            'db' => $this->db,
            'audit' => AuditLogger::null(),
            'authenticator' => new FakeAuthenticator(),
            'diagnostics' => $this->diag,
        ]));
    }

    private function settings(): SettingsStore
    {
        return new SettingsStore($this->db, new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, UploadedFile> $files
     */
    private function request(string $method, string $path, array $body = [], string $ip = self::OFFICE_IP, bool $csrf = true, array $files = []): ResponseInterface
    {
        $uri = 'https://vpn-gw.company.ua/k7f3x' . $path;
        $req = (new ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $ip]);
        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $q);
            $req = $req->withQueryParams($q);
        }
        if ($method === 'POST') {
            if ($csrf) {
                $body['csrf'] = $this->session->csrfToken();
            }
            $req = $req->withParsedBody($body)->withUploadedFiles($files);
        }
        return $this->app->handle($req);
    }

    private function login(): void
    {
        $r = $this->request('POST', '/admin/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/k7f3x/admin', $r->getHeaderLine('Location'));
    }

    private static function body(ResponseInterface $r): string
    {
        return (string) $r->getBody();
    }

    // ---- access control ---------------------------------------------------------------

    public function testPanelIsInvisibleFromTheInternet(): void
    {
        foreach (['/admin', '/admin/login', '/admin/settings/mikrotik', '/admin/logs', '/admin/backups', '/admin/update'] as $path) {
            self::assertSame(404, $this->request('GET', $path, [], self::PUBLIC_IP)->getStatusCode(), $path);
        }
        $r = $this->request('POST', '/admin/login', ['username' => 'admin', 'password' => self::PASSWORD], self::PUBLIC_IP);
        self::assertSame(404, $r->getStatusCode());
        self::assertNull($this->session->str('admin_user'));
    }

    public function testEncodedPathDoesNotBypassNetworkCheck(): void
    {
        self::assertSame(404, $this->request('GET', '/%61dmin/login', [], self::PUBLIC_IP)->getStatusCode());
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        foreach (['/admin', '/admin/settings/ad', '/admin/logs', '/admin/backups', '/admin/update', '/admin/password'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(303, $r->getStatusCode(), $path);
            self::assertSame('/k7f3x/admin/login', $r->getHeaderLine('Location'));
        }
        self::assertSame(200, $this->request('GET', '/admin/login')->getStatusCode());
    }

    public function testLoginAndDashboard(): void
    {
        $this->login();
        $r = $this->request('GET', '/admin');
        self::assertSame(200, $r->getStatusCode());
        $html = self::body($r);
        self::assertStringContainsString('Огляд', $html);
        self::assertStringContainsString(Version::CURRENT, $html);
        self::assertStringContainsString('admin', $html);
        self::assertStringNotContainsString(self::PASSWORD, $html);
        self::assertNotNull($this->db->findAdmin('admin')['last_login_at'] ?? null);
    }

    public function testWrongPasswordAndLockout(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $r = $this->request('POST', '/admin/login', ['username' => 'admin', 'password' => 'wrong-password']);
            self::assertSame(401, $r->getStatusCode());
        }
        $r = $this->request('POST', '/admin/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(429, $r->getStatusCode(), 'locked even with the right password');
        self::assertNull($this->session->str('admin_user'));
        $this->clock->advance(901);
        $this->boot();
        $this->login();
    }

    public function testUnknownUserLooksLikeWrongPassword(): void
    {
        $a = self::body($this->request('POST', '/admin/login', ['username' => 'nobody', 'password' => 'x']));
        $b = self::body($this->request('POST', '/admin/login', ['username' => 'admin', 'password' => 'x']));
        self::assertStringContainsString('Неправильний логін або пароль', $a);
        self::assertStringContainsString('Неправильний логін або пароль', $b);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $r = $this->request('POST', '/admin/login', ['username' => 'admin', 'password' => self::PASSWORD], self::OFFICE_IP, false);
        self::assertSame(403, $r->getStatusCode());
    }

    public function testSessionIsBoundToAddressAndExpires(): void
    {
        $this->login();
        self::assertSame(303, $this->request('GET', '/admin', [], self::OTHER_OFFICE_IP)->getStatusCode());
        self::assertNull($this->session->str('admin_user'));

        $this->login();
        $this->clock->advance(1700);
        self::assertSame(200, $this->request('GET', '/admin')->getStatusCode());
        $this->clock->advance(1801);
        self::assertSame(303, $this->request('GET', '/admin')->getStatusCode(), 'idle timeout');
    }

    public function testLogout(): void
    {
        $this->login();
        $r = $this->request('POST', '/admin/logout');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame(303, $this->request('GET', '/admin')->getStatusCode());
    }

    public function testChangePassword(): void
    {
        $this->login();
        $r = $this->request('POST', '/admin/password', ['current' => 'bad', 'new' => 'New-Password-2026', 'repeat' => 'New-Password-2026']);
        self::assertSame(422, $r->getStatusCode());
        $r = $this->request('POST', '/admin/password', ['current' => self::PASSWORD, 'new' => 'short', 'repeat' => 'short']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('12 символів', self::body($r));
        $r = $this->request('POST', '/admin/password', ['current' => self::PASSWORD, 'new' => 'New-Password-2026', 'repeat' => 'New-Password-2026']);
        self::assertSame(303, $r->getStatusCode());
        $auth = new AdminAuth($this->db, $this->clock);
        self::assertTrue($auth->verify('admin', 'New-Password-2026'));
        self::assertFalse($auth->verify('admin', self::PASSWORD));
    }

    // ---- settings ---------------------------------------------------------------------

    public function testSettingsPagesRender(): void
    {
        $this->login();
        foreach (['mikrotik' => 'Маршрутизатори', 'mail' => 'SMTP-сервер', 'ad' => 'Контролери домену', 'logs' => 'Файл журналу', 'backup' => 'Година запуску'] as $section => $text) {
            $r = $this->request('GET', "/admin/settings/$section");
            self::assertSame(200, $r->getStatusCode(), $section);
            self::assertStringContainsString($text, self::body($r));
        }
        self::assertSame(404, $this->request('GET', '/admin/settings/secrets')->getStatusCode());
    }

    public function testSecretsAreNeverSentToTheBrowser(): void
    {
        $this->login();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringNotContainsString('value="secret"', $html);
        self::assertStringContainsString('задано', $html);
        $html = self::body($this->request('GET', '/admin/settings/backup'));
        self::assertStringNotContainsString('backup-passphrase-1', $html);
    }

    /** @return array<string, string> the MikroTik form as the browser would submit it */
    private function mikrotikForm(): array
    {
        return [
            'action' => 'save',
            'routers' => [
                ['name' => 'core', 'host' => '10.0.10.1', 'version' => '7', 'port' => '8443'],
                ['name' => 'branch', 'host' => '10.0.20.1', 'version' => '6', 'port' => '8729'],
                ['name' => '', 'host' => '', 'version' => '7', 'port' => ''],
            ],
            'MT_USER' => 'vpn_portal',
            'MT_PASS' => '',
            'MT_CA_FILE' => '/etc/ssl/company-ca.pem',
            'MT_LIST' => 'Allowed_VPN_Users',
            'MT_TIMEOUT' => '10:00:00',
        ];
    }

    public function testSaveMikrotikSettings(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['MT_LIST'] = 'VPN_2FA';
        $form['MT_TIMEOUT'] = '08:00:00';
        $form['routers'][2] = ['name' => 'dr', 'host' => 'mt-dr.company.local', 'version' => '7', 'port' => '443'];
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));

        $saved = $this->settings()->values();
        self::assertSame('VPN_2FA', $saved['MT_LIST']);
        self::assertSame('08:00:00', $saved['MT_TIMEOUT']);
        self::assertArrayNotHasKey('MT_PASS', $saved, 'empty secret field keeps the old value');
        self::assertArrayNotHasKey('MT_USER', $saved, 'unchanged values are not stored');
        $routers = json_decode($saved['ROUTERS'], true);
        self::assertIsArray($routers);
        self::assertCount(3, $routers);
        self::assertSame(['name' => 'dr', 'host' => 'mt-dr.company.local', 'version' => 7, 'port' => 443], $routers[2]);

        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringContainsString('змінено в панелі', $html);
        self::assertStringContainsString('VPN_2FA', $html);
    }

    public function testDeleteRouterRow(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][1]['delete'] = '1';
        self::assertSame(303, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode());
        $routers = json_decode($this->settings()->values()['ROUTERS'], true);
        self::assertIsArray($routers);
        self::assertCount(1, $routers);
    }

    public function testInvalidSettingsAreNotSaved(): void
    {
        $this->login();
        $cases = [
            ['MT_TIMEOUT', '10 годин', 'ГГ:ХХ:СС'],
            ['MT_LIST', 'x comment=y', 'Address-list'],
            ['MT_CA_FILE', '/root/ca.pem', '/etc/ssl/'],
        ];
        foreach ($cases as [$key, $value, $message]) {
            $form = $this->mikrotikForm();
            $form[$key] = $value;
            $r = $this->request('POST', '/admin/settings/mikrotik', $form);
            self::assertSame(422, $r->getStatusCode(), $key);
            self::assertStringContainsString($message, self::body($r), $key);
        }
        $form = $this->mikrotikForm();
        $form['routers'] = [['name' => '', 'host' => '', 'version' => '7', 'port' => '']];
        self::assertSame(422, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode(), 'no routers');
        $form = $this->mikrotikForm();
        $form['routers'][1]['name'] = 'core';
        self::assertSame(422, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode(), 'duplicate names');
        self::assertSame([], $this->settings()->values());
    }

    public function testSecretIsEncryptedAndCanBeCleared(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['MT_PASS'] = 'N3w-Router-Pass';
        self::assertSame(303, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode());
        self::assertSame('N3w-Router-Pass', $this->settings()->values()['MT_PASS']);
        $raw = $this->db->allSettings()['MT_PASS'];
        self::assertTrue($raw['encrypted']);
        self::assertStringNotContainsString('N3w-Router-Pass', $raw['value']);

        $this->boot();
        $form = $this->mikrotikForm();
        $form['MT_PASS__clear'] = '1';
        self::assertSame(303, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode());
        self::assertSame('', $this->settings()->values()['MT_PASS']);
    }

    public function testResetToEnvValue(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['MT_LIST'] = 'Other_List';
        $this->request('POST', '/admin/settings/mikrotik', $form);
        $this->boot();
        $r = $this->request('POST', '/admin/settings/mikrotik', ['action' => 'reset:MT_LIST']);
        self::assertSame(303, $r->getStatusCode());
        self::assertArrayNotHasKey('MT_LIST', $this->settings()->values());
        self::assertSame(400, $this->request('POST', '/admin/settings/mikrotik', ['action' => 'reset:APP_KEY'])->getStatusCode());
    }

    public function testMailAndLogsAndBackupSections(): void
    {
        $this->login();
        $r = $this->request('POST', '/admin/settings/mail', [
            'action' => 'save', 'SMTP_HOST' => 'smtp.company.ua', 'SMTP_PORT' => '587', 'SMTP_USER' => '', 'SMTP_PASS' => '',
            'SMTP_FROM' => 'vpn-gw@company.ua', 'SMTP_CA_FILE' => '', 'EMAIL_OTP_ENABLED' => '0', 'IT_CONTACT' => 'ІТ, тел. 123',
        ]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('smtp.company.ua', $this->settings()->values()['SMTP_HOST']);

        $this->boot();
        $r = $this->request('POST', '/admin/settings/logs', [
            'action' => 'save', 'LOG_PATH' => $this->tmp . '/logs/audit.log', 'LOG_RETENTION_DAYS' => '365', 'LOG_LEVEL' => 'warning',
            'LOG_EVENTS' => ['auth', 'access'], 'LOG_SYSLOG' => '0', 'LOG_SYSLOG_FACILITY' => 'local3', 'LOG_REMOTE' => 'siem.company.local:514',
        ]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $v = $this->settings()->values();
        self::assertSame('auth,access,admin,system', $v['LOG_EVENTS'], 'admin and system are always on');
        self::assertSame('false', $v['LOG_SYSLOG']);
        self::assertSame('siem.company.local:514', $v['LOG_REMOTE']);

        $this->boot();
        $r = $this->request('POST', '/admin/settings/logs', [
            'action' => 'save', 'LOG_PATH' => '/etc/passwd.log', 'LOG_RETENTION_DAYS' => '365', 'LOG_LEVEL' => 'warning',
            'LOG_EVENTS' => ['auth'], 'LOG_SYSLOG' => '0', 'LOG_SYSLOG_FACILITY' => 'local3', 'LOG_REMOTE' => '',
        ]);
        self::assertSame(422, $r->getStatusCode(), 'log file outside the allowed directories');

        $r = $this->request('POST', '/admin/settings/backup', [
            'action' => 'save', 'BACKUP_ENABLED' => '1', 'BACKUP_HOUR' => '3', 'BACKUP_KEEP' => '30', 'BACKUP_PASSPHRASE' => '', 'BACKUP_PASSPHRASE__clear' => '1',
        ]);
        self::assertSame(422, $r->getStatusCode(), 'scheduled backups need a passphrase');
        $r = $this->request('POST', '/admin/settings/backup', [
            'action' => 'save', 'BACKUP_ENABLED' => '1', 'BACKUP_HOUR' => '3', 'BACKUP_KEEP' => '30', 'BACKUP_PASSPHRASE' => 'new-long-passphrase',
        ]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('new-long-passphrase', $this->settings()->values()['BACKUP_PASSPHRASE']);
    }

    public function testTestButtons(): void
    {
        $this->login();
        $html = self::body($this->request('POST', '/admin/settings/mikrotik', ['action' => 'test']));
        self::assertStringContainsString('Connection timed out', $html);
        $html = self::body($this->request('POST', '/admin/settings/ad', ['action' => 'test', 'test_user' => 'Ivanenko', 'test_password' => 'right']));
        self::assertStringContainsString('Вхід успішний', $html);
        $this->request('POST', '/admin/settings/mail', ['action' => 'test', 'test_to' => 'it@company.ua']);
        self::assertSame(['routers', 'ldap:ivanenko', 'mail:it@company.ua'], $this->diag->calls);
        self::assertSame([], $this->settings()->values(), 'tests do not save anything');
    }

    // ---- logs -------------------------------------------------------------------------

    public function testLogViewerFilters(): void
    {
        $lines = [
            ['message' => 'login_ok', 'context' => ['event' => 'login_ok', 'category' => 'auth', 'user' => 'ivanenko', 'ip' => '203.0.113.5'], 'level_name' => 'INFO', 'datetime' => '2026-11-15T08:00:00+02:00'],
            ['message' => 'mt_error', 'context' => ['event' => 'mt_error', 'category' => 'system', 'router' => 'branch', 'error' => 'timeout'], 'level_name' => 'WARNING', 'datetime' => '2026-11-15T08:01:00+02:00'],
            ['message' => 'login_fail', 'context' => ['event' => 'login_fail', 'category' => 'auth', 'user' => 'petrenko', 'ip' => '198.51.100.7'], 'level_name' => 'WARNING', 'datetime' => '2026-11-15T08:02:00+02:00'],
        ];
        file_put_contents($this->tmp . '/logs/audit.log', implode("\n", array_map(static fn ($l) => json_encode($l), $lines)) . "\nnot json\n");
        $this->login();
        $html = self::body($this->request('GET', '/admin/logs'));
        self::assertStringContainsString('ivanenko', $html);
        self::assertStringContainsString('router=branch', $html);
        self::assertLessThan(strpos($html, 'ivanenko'), strpos($html, 'petrenko'), 'newest first');

        $html = self::body($this->request('GET', '/admin/logs?level=warning&category=auth'));
        self::assertStringContainsString('petrenko', $html);
        self::assertStringNotContainsString('ivanenko', $html);
        self::assertStringNotContainsString('router=branch', $html);

        $html = self::body($this->request('GET', '/admin/logs?q=203.0.113'));
        self::assertStringContainsString('ivanenko', $html);
        self::assertStringNotContainsString('petrenko', $html);
    }

    // ---- backups ----------------------------------------------------------------------

    public function testRequestBackupQueuesAJob(): void
    {
        $this->login();
        $r = $this->request('POST', '/admin/backups');
        self::assertSame(303, $r->getStatusCode());
        $jobs = glob($this->tmp . '/data/spool/*.job') ?: [];
        self::assertCount(1, $jobs);
        $job = json_decode((string) file_get_contents($jobs[0]), true);
        self::assertIsArray($job);
        self::assertSame('backup', $job['type']);
        self::assertSame('admin', $job['by']);
        self::assertStringEndsWith('/admin/jobs/' . $job['id'], $r->getHeaderLine('Location'));

        $html = self::body($this->request('GET', '/admin/jobs/' . $job['id']));
        self::assertStringContainsString('у черзі', $html);
        self::assertStringContainsString('http-equiv="refresh"', $html);

        $this->request('POST', '/admin/backups');
        self::assertCount(1, glob($this->tmp . '/data/spool/*.job') ?: [], 'no second job while one is queued');
    }

    public function testBackupNeedsPassphrase(): void
    {
        $this->cfgOverride['BACKUP_PASSPHRASE'] = '';
        $this->cfgOverride['BACKUP_ENABLED'] = 'false';
        $this->boot();
        $this->login();
        $this->request('POST', '/admin/backups');
        self::assertSame([], glob($this->tmp . '/data/spool/*.job') ?: []);
        self::assertStringContainsString('пароль шифрування', self::body($this->request('GET', '/admin/backups')));
    }

    public function testFinishedJobAndDownload(): void
    {
        $name = 'vpn-gw-20261115-020000-auto.vgbk';
        file_put_contents($this->tmp . '/backups/' . $name, 'VGBK-encrypted');
        file_put_contents($this->tmp . '/agent/status/20261115-020000-abcdef01.json', json_encode([
            'id' => '20261115-020000-abcdef01', 'type' => 'backup', 'state' => 'ok', 'by' => 'розклад',
            'created' => '2026-11-15 02:00:00', 'finished' => '2026-11-15 02:00:09', 'message' => "Створено $name", 'file' => $name,
        ]));
        $this->login();
        $html = self::body($this->request('GET', '/admin/backups'));
        self::assertStringContainsString($name, $html);
        self::assertStringContainsString('за розкладом', $html);
        self::assertStringContainsString('успішно', $html);

        $r = $this->request('GET', '/admin/backups/' . $name);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('VGBK-encrypted', self::body($r));
        self::assertStringContainsString('attachment', $r->getHeaderLine('Content-Disposition'));

        self::assertSame(404, $this->request('GET', '/admin/backups/..%2F..%2Fetc%2Fpasswd')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/admin/backups/db.sqlite')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/admin/backups/' . $name, [], self::PUBLIC_IP)->getStatusCode());
        self::assertSame(404, $this->request('GET', '/admin/jobs/20261115-020000-ffffffff')->getStatusCode());
    }

    // ---- update -----------------------------------------------------------------------

    /** @return array{0: string, 1: string} [zip path, sha256] */
    private function fakeRelease(string $version): array
    {
        $dir = $this->tmp . '/rel';
        @mkdir("$dir/vpn-gw-$version", 0700, true);
        file_put_contents("$dir/vpn-gw-$version/VERSION", "$version\n");
        $zip = "$dir/vpn-gw-$version.zip";
        @unlink($zip);
        exec('cd ' . escapeshellarg($dir) . ' && zip -qr ' . escapeshellarg(basename($zip)) . ' ' . escapeshellarg("vpn-gw-$version"));
        return [$zip, (string) hash_file('sha256', $zip)];
    }

    private function upload(string $path, string $name): UploadedFile
    {
        $copy = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
        copy($path, $copy);
        return new UploadedFile($copy, $name, 'application/zip', (int) filesize($copy), UPLOAD_ERR_OK);
    }

    private function uploadText(string $text, string $name): UploadedFile
    {
        $copy = $this->tmp . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($copy, $text);
        return new UploadedFile($copy, $name, 'text/plain', strlen($text), UPLOAD_ERR_OK);
    }

    public function testUpdateUploadIsVerifiedAndQueued(): void
    {
        $this->login();
        [$zip, $sha] = $this->fakeRelease('9.1');
        $r = $this->request('POST', '/admin/update', [], self::OFFICE_IP, true, [
            'archive' => $this->upload($zip, 'vpn-gw-9.1.zip'),
            'checksum' => $this->uploadText("$sha  vpn-gw-9.1.zip\n", 'vpn-gw-9.1.zip.sha256'),
        ]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $jobs = glob($this->tmp . '/data/spool/*.job') ?: [];
        self::assertCount(1, $jobs);
        $job = json_decode((string) file_get_contents($jobs[0]), true);
        self::assertIsArray($job);
        self::assertSame(['update', '9.1', $sha], [$job['type'], $job['version'], $job['sha256']]);
        self::assertFileExists($this->tmp . '/data/spool/' . $job['id'] . '.zip');
        self::assertSame($sha, hash_file('sha256', $this->tmp . '/data/spool/' . $job['id'] . '.zip'));
    }

    public function testUpdateRejectsBadUploads(): void
    {
        $this->login();
        [$zip, $sha] = $this->fakeRelease('9.1');
        $cases = [
            'wrong checksum' => [['archive' => $this->upload($zip, 'vpn-gw-9.1.zip')], ['sha256' => str_repeat('a', 64)], 'не збігається'],
            'no checksum' => [['archive' => $this->upload($zip, 'vpn-gw-9.1.zip')], [], 'SHA-256'],
            'checksum of another file' => [['archive' => $this->upload($zip, 'vpn-gw-9.1.zip')], ['sha256' => "$sha  vpn-gw-9.2.zip"], 'іншому файлу'],
            'bad name' => [['archive' => $this->upload($zip, 'update.zip')], ['sha256' => $sha], 'vpn-gw-X.Y.zip'],
            'downgrade' => [['archive' => $this->upload($zip, 'vpn-gw-0.0.9.zip')], ['sha256' => $sha], 'старша'],
            'same version' => [['archive' => $this->upload($zip, 'vpn-gw-' . Version::CURRENT . '.zip')], ['sha256' => $sha], 'уже встановлена'],
        ];
        foreach ($cases as $label => [$files, $body, $message]) {
            $r = $this->request('POST', '/admin/update', $body, self::OFFICE_IP, true, $files);
            self::assertSame(422, $r->getStatusCode(), $label);
            self::assertStringContainsString($message, self::body($r), $label);
        }
        $notZip = $this->tmp . '/fake.zip';
        file_put_contents($notZip, str_repeat('x', 200));
        $r = $this->request('POST', '/admin/update', ['sha256' => (string) hash_file('sha256', $notZip)], self::OFFICE_IP, true, ['archive' => $this->upload($notZip, 'vpn-gw-9.1.zip')]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('не є ZIP', self::body($r));
        self::assertSame([], glob($this->tmp . '/data/spool/*') ?: [], 'nothing left in the spool');
    }
}
