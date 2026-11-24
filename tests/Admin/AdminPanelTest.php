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
use VpnGw\Tests\Support\FakeRouter;
use VpnGw\RouterOs\AccessGranter;
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
    private ?FakeRouter $router = null;
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
            'granter' => new AccessGranter(
                [$this->router ??= new FakeRouter('core')], 'Allowed_VPN_Users', '10:00:00', 36000,
                $this->tmp . '/locks', $this->db, AuditLogger::null(), $this->clock,
            ),
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
        $html = self::body($this->request('GET', '/admin/routers/edit/core'));
        self::assertStringNotContainsString('value="secret"', $html);
        self::assertStringContainsString('типовий з .env', $html);
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
        self::assertSame(['name' => 'dr', 'host' => 'mt-dr.company.local', 'version' => 7, 'port' => 443, 'transport' => 'api'], $routers[2]);

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

    public function testSshRouterWithKeyIsSavedEncrypted(): void
    {
        $this->login();
        $key = \phpseclib3\Crypt\EC::createKey('Ed25519')->toString('OpenSSH');
        $form = $this->mikrotikForm();
        $form['routers'][2] = ['name' => 'old', 'host' => '10.0.30.1', 'version' => '6', 'port' => '22', 'transport' => 'ssh'];
        $form['MT_SSH_USER'] = 'vpn_portal';
        $form['MT_SSH_KEY'] = str_replace("\n", "\r\n", $key);
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $v = $this->settings()->values();
        self::assertSame(trim($key) . "\n", $v['MT_SSH_KEY'], 'line endings normalised');
        foreach (['ROUTERS', 'MT_SSH_USER', 'MT_SSH_KEY'] as $k) {
            self::assertTrue($this->db->allSettings()[$k]['encrypted'], $k);
        }
        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringNotContainsString('PRIVATE KEY-----' . "\n", $html);
        self::assertStringNotContainsString(substr(explode("\n", trim($key))[1], 0, 20), $html, 'key is write-only');
        self::assertStringContainsString('не підтверджено', $html, 'host key not pinned yet');
        self::assertStringContainsString('SSH-ключ не підтверджено для: old', self::body($this->request('GET', '/admin')));
    }

    public function testInvalidSshKeyIsRejected(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][2] = ['name' => 'old', 'host' => '10.0.30.1', 'version' => '6', 'port' => '22', 'transport' => 'ssh'];
        $form['MT_SSH_USER'] = 'vpn_portal';
        $form['MT_SSH_KEY'] = "-----BEGIN OPENSSH PRIVATE KEY-----\nnot-a-key\n-----END OPENSSH PRIVATE KEY-----";
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('ключ не читається', self::body($r));
        $form['MT_SSH_KEY'] = '';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(422, $r->getStatusCode(), 'SSH router without password or key');
        self::assertStringContainsString('або приватний ключ', self::body($r));
    }

    public function testPinnedHostKeysFollowTheRouter(): void
    {
        $fp = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $this->cfgOverride['ROUTERS'] = '[{"name":"a","host":"10.0.30.1","version":6,"port":22,"transport":"ssh"},{"name":"b","host":"10.0.30.2","version":7,"port":22,"transport":"ssh"},{"name":"c","host":"10.0.30.3","version":7,"port":22,"transport":"ssh"}]';
        $this->cfgOverride['MT_SSH_USER'] = 'vpn_portal';
        $this->cfgOverride['MT_SSH_PASS'] = 'x';
        $this->cfgOverride['MT_SSH_HOSTKEYS'] = json_encode(['a' => $fp, 'b' => $fp, 'c' => $fp]) ?: '';
        $this->boot();
        $this->login();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertSame(3, substr_count($html, 'ключ підтверджено'));
        self::assertStringContainsString('SHA256:AAAAAAAAAAA', self::body($this->request('GET', '/admin/routers/edit/a')));
        $form = $this->mikrotikForm();
        $form['routers'] = [
            ['name' => 'a', 'host' => '10.0.30.1', 'version' => '6', 'port' => '22', 'transport' => 'ssh'],
            ['name' => 'b', 'host' => '10.0.30.22', 'version' => '7', 'port' => '22', 'transport' => 'ssh'],
            ['name' => 'c', 'host' => '10.0.30.3', 'version' => '7', 'port' => '22', 'transport' => 'ssh', 'reset_key' => '1'],
        ];
        $form['MT_SSH_USER'] = 'vpn_portal';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame(['a' => $fp], json_decode($this->settings()->values()['MT_SSH_HOSTKEYS'], true),
            'b: address changed, c: reset — both must be confirmed again');
    }

    public function testLdapWithoutCertificates(): void
    {
        $this->login();
        $form = ['action' => 'save', 'LDAP_SECURITY' => 'starttls-insecure', 'LDAP_HOSTS' => 'ldap://dc01.company.local:389',
            'LDAP_UPN_SUFFIX' => '@company.local', 'LDAP_BASE_DN' => 'DC=company,DC=local',
            'LDAP_VPN_GROUP_DN' => 'CN=VPN-Users,OU=Groups,DC=company,DC=local', 'LDAP_CA_FILE' => ''];
        $r = $this->request('POST', '/admin/settings/ad', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $this->boot();
        self::assertStringContainsString('сертифікат контролера домену не перевіряється', self::body($this->request('GET', '/admin')));

        $form['LDAP_SECURITY'] = 'ldaps';
        $r = $this->request('POST', '/admin/settings/ad', $form);
        self::assertSame(422, $r->getStatusCode(), 'ldaps with ldap:// hosts');
        $form['LDAP_HOSTS'] = 'ldaps://dc01.company.local:636';
        $r = $this->request('POST', '/admin/settings/ad', $form);
        self::assertSame(422, $r->getStatusCode(), 'verification needs a CA file');
        self::assertStringContainsString('потрібен «Сертифікат CA»', self::body($r));

        $form = ['LDAP_SECURITY' => 'plain', 'LDAP_HOSTS' => 'ldap://dc01.company.local'] + $form;
        self::assertSame(303, $this->request('POST', '/admin/settings/ad', $form)->getStatusCode());
        $this->boot();
        self::assertStringContainsString('без шифрування', self::body($this->request('GET', '/admin')));
    }

    public function testAdSettingsUseDomainAndGroupName(): void
    {
        $this->login();
        $html = self::body($this->request('GET', '/admin/settings/ad'));
        self::assertStringContainsString('name="LDAP_DOMAIN"', $html);
        self::assertStringContainsString('name="LDAP_VPN_GROUP"', $html);
        self::assertStringNotContainsString('name="LDAP_BASE_DN"', $html);
        self::assertStringNotContainsString('name="LDAP_VPN_GROUP_DN"', $html);
        $r = $this->request('POST', '/admin/settings/ad', ['action' => 'save', 'LDAP_SECURITY' => 'ldaps',
            'LDAP_HOSTS' => 'ldaps://dc01.nerc.gov.ua:636', 'LDAP_DOMAIN' => 'nerc.gov.ua', 'LDAP_VPN_GROUP' => 'VPN-Users',
            'LDAP_CA_FILE' => '/etc/ssl/company-ca.pem']);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/ad'));
        self::assertStringContainsString('value="nerc.gov.ua"', $html);
        self::assertStringNotContainsString('DC=nerc', $html);
        $r = $this->request('POST', '/admin/settings/ad', ['action' => 'save', 'LDAP_SECURITY' => 'ldaps',
            'LDAP_HOSTS' => 'ldaps://dc01.nerc.gov.ua:636', 'LDAP_DOMAIN' => 'DC=nerc,DC=gov,DC=ua', 'LDAP_VPN_GROUP' => 'VPN-Users',
            'LDAP_CA_FILE' => '/etc/ssl/company-ca.pem']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('nerc.gov.ua', self::body($r));
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

    public function testJobLogLinesColouredByMarker(): void
    {
        $id = '20261122-101500-0badc0de';
        file_put_contents($this->tmp . "/agent/status/$id.json", json_encode([
            'id' => $id, 'type' => 'update', 'state' => 'failed', 'by' => 'admin', 'version' => '0.10.3',
            'created' => '2026-11-22 10:15:00', 'finished' => '2026-11-22 10:15:30', 'message' => 'install.sh завершився з помилкою',
        ]));
        file_put_contents($this->tmp . "/agent/status/$id.log", implode("\n", [
            '==> Перевірка архіву',
            "\e[32m[ OK ]\e[0m sha256 збігається",
            '[WARN] Немає <USER_GUIDE_PDF>',
            '[FAIL] nginx & apache одночасно',
            '[FATAL] Зупинено',
        ]) . "\n");
        $this->login();
        $html = self::body($this->request('GET', "/admin/jobs/$id"));
        self::assertStringContainsString('<span class="log-ok">[ OK ] sha256 збігається</span>', $html);
        self::assertStringContainsString('<span class="log-warn">[WARN] Немає &lt;USER_GUIDE_PDF&gt;</span>', $html);
        self::assertStringContainsString('<span class="log-fail">[FAIL] nginx &amp; apache одночасно</span>', $html);
        self::assertStringContainsString('<span class="log-fail">[FATAL] Зупинено</span>', $html);
        self::assertStringContainsString("log-output\">==&gt; Перевірка архіву\n", $html, 'unmarked lines stay plain');
        self::assertStringNotContainsString("\e[", $html);
        self::assertStringContainsString('log-legend', $html);
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

    /** 0.5: own login and password per router; the password is write-only. */
    public function testPerRouterCredentials(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][0] += ['orig' => 'core', 'user' => 'portal_core', 'password' => 'Core-Pa$$-1'];
        $form['routers'][1] += ['orig' => 'branch'];
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $saved = $this->settings()->values();
        self::assertSame('{"core":"Core-Pa$$-1"}', $saved['ROUTER_PASSWORDS']);
        self::assertSame('portal_core', json_decode($saved['ROUTERS'], true)[0]['user']);
        self::assertArrayNotHasKey('user', json_decode($saved['ROUTERS'], true)[1], 'no own user = common one');
        foreach ($this->db->allSettings() as $key => $row) {
            if ($key === 'ROUTER_PASSWORDS') {
                self::assertTrue($row['encrypted']);
                self::assertStringNotContainsString('Core-Pa', $row['value']);
            }
        }

        $this->boot();
        $html = self::body($this->request('GET', '/admin/routers/edit/core'));
        self::assertStringNotContainsString('Core-Pa', $html);
        self::assertStringContainsString('value="portal_core"', $html);
        self::assertStringContainsString('задано; залиште порожнім', $html);
        self::assertStringContainsString('name="router[clear_password]"', $html);
        self::assertStringContainsString('placeholder="vpn_portal"', self::body($this->request('GET', '/admin/routers/edit/branch')));
        self::assertStringNotContainsString('Core-Pa', self::body($this->request('GET', '/admin/settings/mikrotik')));

        // empty password field keeps the stored one; renaming the row keeps it too
        $form['routers'][0] = ['orig' => 'core', 'name' => 'core2', 'host' => '10.0.10.1', 'version' => '7', 'port' => '8443', 'user' => 'portal_core'];
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('{"core2":"Core-Pa$$-1"}', $this->settings()->values()['ROUTER_PASSWORDS']);
        $this->boot();

        // a new address needs the password again
        $form['routers'][0] = ['orig' => 'core2', 'name' => 'core2', 'host' => '10.9.9.9', 'version' => '7', 'port' => '8443', 'user' => 'portal_core'];
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('введіть його пароль ще раз', self::body($r));
        $form['routers'][0]['password'] = 'New-Pa$$-2';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('{"core2":"New-Pa$$-2"}', $this->settings()->values()['ROUTER_PASSWORDS']);
        $this->boot();

        // «типовий» removes the own password
        $form['routers'][0] = ['orig' => 'core2', 'name' => 'core2', 'host' => '10.9.9.9', 'version' => '7', 'port' => '8443', 'clear_password' => '1'];
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('{}', $this->settings()->values()['ROUTER_PASSWORDS']);
        self::assertArrayNotHasKey('user', json_decode($this->settings()->values()['ROUTERS'], true)[0]);
    }

    public function testRouterUserIsValidated(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][0]['user'] = 'x" ; /system reset';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('користувач — лише латиниця', self::body($r));
    }

    public function testMissingSshCredentialsNameTheRouter(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][1] = ['name' => 'branch', 'host' => '10.0.20.1', 'version' => '6', 'port' => '22', 'transport' => 'ssh', 'user' => 'portal_b'];
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('маршрутизатор «branch»', self::body($r));
    }


    /** 0.7: address-list per router in the routers table. */
    public function testPerRouterAddressListInPanel(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][0]['list'] = 'VPN_core';
        $form['routers'][1]['list'] = '';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $routers = json_decode($this->settings()->values()['ROUTERS'], true);
        self::assertSame('VPN_core', $routers[0]['list']);
        self::assertArrayNotHasKey('list', $routers[1], 'empty = MT_LIST');

        $this->boot();
        $html = self::body($this->request('GET', '/admin/routers/edit/core'));
        self::assertStringContainsString('name="router[list]" value="VPN_core"', $html);
        self::assertStringContainsString('placeholder="типовий: Allowed_VPN_Users"', self::body($this->request('GET', '/admin/routers/edit/branch')));

        // «Вибрати з MikroTik» on the router's page: its own lists (FakeDiagnostics: core read, branch failed)
        $html = self::body($this->request('POST', '/admin/routers/edit/core', ['action' => 'lists']));
        self::assertStringContainsString('<select name="router[list]"', $html);
        self::assertStringContainsString('VPN_core — на маршрутизаторі не знайдено', $html);
        self::assertStringContainsString('типовий: Allowed_VPN_Users — 3 адреси, 2 правила', $html);
        self::assertStringContainsString('<option value="blacklist">blacklist — 120 адрес, 1 правило</option>', $html);
        $html = self::body($this->request('POST', '/admin/routers/edit/branch', ['action' => 'lists']));
        self::assertStringContainsString('Connection timed out', $html, 'branch could not be read');
        self::assertStringNotContainsString('<select name="router[list]"', $html);

        // choose «інший / новий»
        $form['action'] = 'save';
        $form['routers'][0]['list'] = '__new';
        $form['routers'][0]['list_new'] = 'VPN_2FA';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('VPN_2FA', json_decode($this->settings()->values()['ROUTERS'], true)[0]['list']);

        $form['routers'][0]['list_new'] = 'bad list';
        $r = $this->request('POST', '/admin/settings/mikrotik', $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('address-list — лише латиниця', self::body($r));
    }

    /** 0.8.1: dates as 22.11.2026 09:06:47; «Відключити» for an active access. */
    public function testDisconnectUserFromDashboard(): void
    {
        $now = $this->clock->now();
        $this->db->recordGrant('ivanenko', '198.51.100.7', $now->modify('-1 hour')->format(DATE_ATOM), $now->modify('+9 hours')->format(DATE_ATOM));
        $this->db->recordGrant('petrenko', '198.51.100.8', $now->modify('-12 hours')->format(DATE_ATOM), $now->modify('-2 hours')->format(DATE_ATOM));
        $this->router?->addEntry('Allowed_VPN_Users', '198.51.100.7', '09:00:00', 'User: ivanenko via VPN-2FA');
        $this->login();

        $html = self::body($this->request('GET', '/admin'));
        $expires = \VpnGw\Http\View::formatDate($now->modify('+9 hours')->format(DATE_ATOM));
        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/', $expires);
        self::assertStringContainsString($expires, $html);
        self::assertStringNotContainsString('T', preg_replace('/[^0-9T:+-]/', ' ', $expires) ?? '');
        self::assertSame(1, substr_count($html, 'value="Відключити"') + substr_count($html, '>Відключити</button>'), 'only the active access has the button');

        // only an active access can be closed
        $r = $this->request('POST', '/admin/grants/revoke', ['username' => 'petrenko', 'ip' => '198.51.100.8']);
        self::assertSame(303, $r->getStatusCode());
        self::assertStringContainsString('уже не активний', self::body($this->request('GET', '/admin')));

        $r = $this->request('POST', '/admin/grants/revoke', ['username' => 'ivanenko', 'ip' => '198.51.100.7']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame([], $this->router?->entries, 'IP removed from the address-list');
        $html = self::body($this->request('GET', '/admin'));
        self::assertStringContainsString('Користувача ivanenko (198.51.100.7) відключено', $html);
        self::assertStringNotContainsString('>Відключити</button>', $html);
        self::assertSame(0, $this->db->countActiveGrants($now->format(DATE_ATOM)));
    }

    /** 0.10.7: the dashboard tiles lead to their pages. */
    public function testDashboardTilesAreLinks(): void
    {
        $this->login();
        $html = self::body($this->request('GET', '/admin'));
        foreach (['#grants', '/k7f3x/admin/authenticators', '/k7f3x/admin/settings/mikrotik', '/k7f3x/admin/update'] as $href) {
            self::assertStringContainsString('<a href="' . $href . '" class="tile-link', $html, $href);
        }
        self::assertSame(4, substr_count($html, 'class="tile-link'));
        self::assertStringContainsString('<h5 id="grants">Останні видачі доступу</h5>', $html);
    }

    /** 0.10.4: active lockouts on the dashboard with «Розблокувати». */
    public function testActiveLockoutsAndUnlock(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->db->setLockout('user:ivanenko', $now + 600, 'enroll');
        $this->db->addRateEvent('user:ivanenko', $now - 10);
        $this->db->addRateEvent('mail:ivanenko', $now - 10);
        $this->db->setLockout('ip:198.51.100.9', $now + 300, 'login');
        $this->db->setLockout('user:<b>x', $now + 300, 'mfa');
        $this->db->setLockout('user:expired', $now - 1, 'login');
        $this->login();

        $html = self::body($this->request('GET', '/admin'));
        self::assertStringContainsString('Активні блокування', $html);
        self::assertSame(3, substr_count($html, '>Розблокувати</button>'), 'expired lockouts are not listed');
        self::assertStringContainsString('реєстрація автентифікатора', $html);
        self::assertStringContainsString('<code>198.51.100.9</code>', $html);
        self::assertStringContainsString(\VpnGw\Http\View::formatDate($now + 600), $html);
        self::assertStringContainsString('&lt;b&gt;x', $html);
        self::assertStringNotContainsString('<b>x', $html);
        self::assertStringNotContainsString('expired', $html);

        $r = $this->request('POST', '/admin/lockouts/unlock', ['key' => 'user:ivanenko']);
        self::assertSame(303, $r->getStatusCode());
        self::assertNull($this->db->lockoutUntil('user:ivanenko'));
        self::assertSame(0, $this->db->countRateEvents('user:ivanenko', 0), 'attempt counter cleared');
        self::assertSame(0, $this->db->countRateEvents('mail:ivanenko', 0));
        self::assertNotNull($this->db->lockoutUntil('ip:198.51.100.9'), 'other lockouts stay');
        $html = self::body($this->request('GET', '/admin'));
        self::assertStringContainsString('Логін ivanenko розблоковано', $html);
        self::assertSame(2, substr_count($html, '>Розблокувати</button>'));

        // only a listed, still active lockout can be lifted
        foreach (['user:ivanenko', 'user:expired', 'ip:203.0.113.1', ''] as $key) {
            $this->request('POST', '/admin/lockouts/unlock', ['key' => $key]);
            self::assertStringContainsString('вже не діє', self::body($this->request('GET', '/admin')));
        }
        self::assertNotNull($this->db->lockoutUntil('user:expired'));
    }

    public function testNoLockoutsAndUnlockNeedsLoginAndCsrf(): void
    {
        $this->db->setLockout('ip:198.51.100.9', $this->clock->now()->getTimestamp() + 300, 'login');
        $r = $this->request('POST', '/admin/lockouts/unlock', ['key' => 'ip:198.51.100.9']);
        self::assertSame('/k7f3x/admin/login', $r->getHeaderLine('Location'));
        $this->login();
        $r = $this->request('POST', '/admin/lockouts/unlock', ['key' => 'ip:198.51.100.9'], csrf: false);
        self::assertContains($r->getStatusCode(), [400, 403]);
        self::assertNotNull($this->db->lockoutUntil('ip:198.51.100.9'));
        $this->db->deleteLockout('ip:198.51.100.9');
        self::assertStringContainsString('lockouts-empty', self::body($this->request('GET', '/admin')));
    }

    public function testExternalPortalUrlIsValidated(): void
    {
        $this->login();
        $form = [
            'action' => 'save', 'SMTP_HOST' => 'smtp.company.ua', 'SMTP_PORT' => '587', 'SMTP_USER' => '', 'SMTP_PASS' => '',
            'SMTP_FROM' => 'vpn-gw@company.ua', 'SMTP_CA_FILE' => '', 'EMAIL_OTP_ENABLED' => '0', 'IT_CONTACT' => 'ІТ',
        ];
        self::assertStringContainsString('name="PORTAL_EXTERNAL_URL"', self::body($this->request('GET', '/admin/settings/mail')));
        foreach (['http://vpn-gw.company.ua/heou', 'https://user@vpn-gw.company.ua/', 'https://vpn-gw.company.ua/a?b=1', "https://vpn-gw.company.ua/\nx"] as $bad) {
            $r = $this->request('POST', '/admin/settings/mail', $form + ['PORTAL_EXTERNAL_URL' => $bad]);
            self::assertSame(422, $r->getStatusCode(), $bad);
        }
        $r = $this->request('POST', '/admin/settings/mail', $form + ['PORTAL_EXTERNAL_URL' => 'https://vpn-gw.nerc.gov.ua:11443/heou']);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('https://vpn-gw.nerc.gov.ua:11443/heou', $this->settings()->values()['PORTAL_EXTERNAL_URL']);
    }

    public function testDisconnectNeedsLoginAndCsrf(): void
    {
        $r = $this->request('POST', '/admin/grants/revoke', ['username' => 'ivanenko', 'ip' => '198.51.100.7']);
        self::assertSame('/k7f3x/admin/login', $r->getHeaderLine('Location'));
        $this->login();
        $r = $this->request('POST', '/admin/grants/revoke', ['username' => 'ivanenko', 'ip' => '198.51.100.7'], csrf: false);
        self::assertContains($r->getStatusCode(), [400, 403]);
    }

    public function testDateFormat(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Kyiv');
        try {
            self::assertSame('22.11.2026 09:06:47', \VpnGw\Http\View::formatDate('2026-11-22T09:06:47+02:00'));
            self::assertSame('22.11.2026 09:06:47', \VpnGw\Http\View::formatDate('2026-11-22T07:06:47+00:00'), 'shown in the server time zone');
            self::assertSame('22.11.2026 09:06:47', \VpnGw\Http\View::formatDate('2026-11-22 09:06:47'));
            self::assertSame('22.11.2026 09:06', \VpnGw\Http\View::formatDate('2026-11-22 09:06:47', false));
            self::assertSame('22.11.2026 09:06:47', \VpnGw\Http\View::formatDate(1795331207));
            self::assertSame('—', \VpnGw\Http\View::formatDate('—'));
            self::assertSame('', \VpnGw\Http\View::formatDate(null));
        } finally {
            date_default_timezone_set($tz);
        }
    }

    /** 0.10.8: rotated log files (audit.log.1, audit.log.2.gz) can be chosen and downloaded. */
    public function testRotatedLogFiles(): void
    {
        $dir = $this->tmp . '/logs';
        @mkdir($dir, 0700, true);
        $line = static fn (string $event, string $user, string $time): string => json_encode(['message' => $event, 'datetime' => $time, 'level_name' => 'INFO',
            'context' => ['event' => $event, 'category' => 'auth', 'user' => $user, 'ip' => '198.51.100.7']]) . "\n";
        file_put_contents("$dir/audit.log", $line('login_ok', 'today_user', '2026-11-24T06:15:00+02:00'));
        file_put_contents("$dir/audit.log.1", $line('login_ok', 'yesterday_user', '2026-11-23T14:26:00+02:00'));
        file_put_contents("$dir/audit.log.2.gz", gzencode($line('mfa_ok', 'older_user', '2026-11-22T14:16:00+02:00') . $line('login_fail', 'older<b>', '2026-11-22T14:17:00+02:00')));
        file_put_contents("$dir/other.log", $line('login_ok', 'stranger', '2026-11-24T06:00:00+02:00'));
        touch("$dir/audit.log.1", 1795962360);
        touch("$dir/audit.log.2.gz", 1795875360);
        $this->login();

        $html = self::body($this->request('GET', '/admin/logs'));
        self::assertStringContainsString('today_user', $html);
        self::assertStringNotContainsString('yesterday_user', $html);
        preg_match_all('/<option value="([^"]*)"[^>]*>(audit[^ <]*)/', $html, $m);
        self::assertSame(['', 'audit.log.1', 'audit.log.2.gz'], $m[1], 'current first, then newest; other files not listed');
        self::assertStringNotContainsString('other.log', $html);

        $html = self::body($this->request('GET', '/admin/logs?file=audit.log.1'));
        self::assertStringContainsString('yesterday_user', $html);
        self::assertStringNotContainsString('today_user', $html);
        self::assertStringContainsString('<option value="audit.log.1" selected>', $html);
        self::assertStringContainsString('/logs/audit.log.1</code>', $html);

        $html = self::body($this->request('GET', '/admin/logs?file=audit.log.2.gz&q=older'));
        self::assertStringContainsString('older_user', $html, 'gzip-compressed copy is read');
        self::assertStringContainsString('older&lt;b&gt;', $html);
        self::assertSame(2, substr_count($html, '<td class="text-nowrap event">'));
        $html = self::body($this->request('GET', '/admin/logs?file=audit.log.2.gz&category=auth&q=mfa_ok'));
        self::assertSame(1, substr_count($html, '<td class="text-nowrap event">'));

        // anything else falls back to the current log
        foreach (['../../etc/passwd', 'other.log', '/etc/passwd', 'audit.log.9'] as $bad) {
            $html = self::body($this->request('GET', '/admin/logs?file=' . rawurlencode($bad)));
            self::assertStringContainsString('today_user', $html, $bad);
            self::assertStringNotContainsString('stranger', $html);
        }

        $r = $this->request('GET', '/admin/logs/download?file=audit.log.2.gz');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('attachment; filename="audit.log.2.gz"', $r->getHeaderLine('Content-Disposition'));
        self::assertSame('application/gzip', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('older_user', (string) gzdecode(self::body($r)));
        $r = $this->request('GET', '/admin/logs/download');
        self::assertSame('attachment; filename="audit.log"', $r->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('today_user', self::body($r));
        foreach (['../../etc/passwd', 'other.log', 'audit.log.9'] as $bad) {
            self::assertSame(404, $this->request('GET', '/admin/logs/download?file=' . rawurlencode($bad))->getStatusCode(), $bad);
        }
    }

    /** 0.10.9: «Показати всі» — entries from all log files together. */
    public function testShowAllLogFiles(): void
    {
        $dir = $this->tmp . '/logs';
        @mkdir($dir, 0700, true);
        $line = static fn (string $event, string $user, string $time): string => json_encode(['message' => $event, 'datetime' => $time, 'level_name' => 'INFO',
            'context' => ['event' => $event, 'category' => 'auth', 'user' => $user, 'ip' => '198.51.100.7']]) . "\n";
        file_put_contents("$dir/audit.log", $line('login_ok', 'today_user', '2026-11-24T06:15:00+02:00'));
        file_put_contents("$dir/audit.log.1", $line('login_ok', 'yesterday_user', '2026-11-23T14:26:00+02:00'));
        file_put_contents("$dir/audit.log.2.gz", gzencode($line('mfa_ok', 'older_user', '2026-11-22T14:16:00+02:00')));
        touch("$dir/audit.log.1", 1795962360);
        touch("$dir/audit.log.2.gz", 1795875360);
        $this->login();

        $html = self::body($this->request('GET', '/admin/logs'));
        self::assertStringContainsString('name="file" value="*" class="btn btn-outline-primary btn-sm show-all">Показати всі</button>', $html);
        self::assertStringContainsString('<option value="*">— усі файли (3) —</option>', $html);

        // the button's file=* comes after the select's value
        $html = self::body($this->request('GET', '/admin/logs?file=audit.log.1&q=&level=&category=&file=%2A'));
        self::assertStringContainsString('<option value="*" selected>', $html);
        self::assertStringContainsString('Усі файли журналу: останні 3 записів', $html);
        self::assertStringContainsString('<th>Файл</th>', $html);
        $pos = array_map(static fn (string $u): int => (int) strpos($html, $u), ['today_user', 'yesterday_user', 'older_user']);
        self::assertSame($pos, array_values(array_filter($pos)), 'all three found');
        self::assertTrue($pos[0] < $pos[1] && $pos[1] < $pos[2], 'current file first, then newer rotated copies');
        self::assertStringContainsString('<td class="text-nowrap small text-muted log-src">audit.log.2.gz</td>', $html);
        self::assertStringNotContainsString('/admin/logs/download', $html, 'no single file to download');

        $html = self::body($this->request('GET', '/admin/logs?file=%2A&q=older'));
        self::assertStringContainsString('older_user', $html);
        self::assertStringNotContainsString('today_user', $html);
        self::assertSame(1, substr_count($html, '<td class="text-nowrap event">'));

        $reader = new \VpnGw\Admin\LogReader("$dir/audit.log");
        self::assertSame(['today_user', 'yesterday_user'], array_column($reader->tailAll(2), 'user'), 'stops at the limit');
        self::assertSame(['audit.log', 'audit.log.1'], array_column($reader->tailAll(2), 'file'));
    }

    public function testShowAllDisabledWithOneFile(): void
    {
        @mkdir($this->tmp . '/logs', 0700, true);
        file_put_contents($this->tmp . '/logs/audit.log', "{}\n");
        $this->login();
        $html = self::body($this->request('GET', '/admin/logs'));
        self::assertStringContainsString('show-all" disabled', $html);
        self::assertStringNotContainsString('усі файли (', $html);
    }

    public function testLogDownloadNeedsLogin(): void
    {
        @mkdir($this->tmp . '/logs', 0700, true);
        file_put_contents($this->tmp . '/logs/audit.log', "{}\n");
        $r = $this->request('GET', '/admin/logs/download');
        self::assertSame('/k7f3x/admin/login', $r->getHeaderLine('Location'));
    }

    public function testGzipLogKeepsOnlyTheTail(): void
    {
        $dir = $this->tmp . '/logs';
        @mkdir($dir, 0700, true);
        $body = '';
        for ($i = 0; $i < 500; $i++) {
            $body .= json_encode(['message' => 'login_ok', 'datetime' => '2026-11-22T10:00:00+02:00', 'level_name' => 'INFO',
                'context' => ['event' => 'login_ok', 'category' => 'auth', 'user' => "u$i", 'ip' => '198.51.100.7']]) . "\n";
        }
        file_put_contents("$dir/audit.log.3.gz", gzencode($body));
        $reader = new \VpnGw\Admin\LogReader("$dir/audit.log");
        $all = $reader->tail(1000, [], 8_388_608, 'audit.log.3.gz');
        self::assertCount(500, $all);
        self::assertSame('u499', $all[0]['user'], 'newest first');
        $tail = $reader->tail(1000, [], 2000, 'audit.log.3.gz');
        self::assertGreaterThan(0, count($tail));
        self::assertLessThan(30, count($tail), 'only about maxBytes of the end');
        self::assertSame('u499', $tail[0]['user']);
        self::assertSame([], $reader->tail(10, [], 1000, 'nope.gz'));
    }

    public function testLogEventIsPlainText(): void
    {
        @mkdir($this->tmp . '/logs', 0700, true);
        file_put_contents($this->tmp . '/logs/audit.log', json_encode(['message' => 'login_fail', 'datetime' => '2026-11-22T09:06:47+02:00', 'level_name' => 'WARNING',
            'context' => ['event' => 'login_fail', 'category' => 'auth', 'user' => 'mhlushak', 'ip' => '172.20.2.29', 'reason' => 'bad_ip']]) . "\n");
        $this->login();
        $html = self::body($this->request('GET', '/admin/logs'));
        self::assertStringContainsString('<td class="text-nowrap event">login_fail</td>', $html);
        self::assertStringContainsString(\VpnGw\Http\View::formatDate('2026-11-22T09:06:47+02:00'), $html);
        self::assertStringNotContainsString('class="badge', preg_replace('/<nav.*?<\/nav>/s', '', $html) ?? '');
    }

    /** 0.10.6: «Відключити» / «Включити» / «Видалити» on a router card; grey and light-green cards. */
    public function testRouterDisableEnable(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][1] += ['transport' => 'api', 'user' => 'portal_branch', 'password' => 'Branch-Pass-1', 'list' => 'VPN_branch'];
        self::assertSame(303, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode());
        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertSame(2, substr_count($html, 'router-card router-on'), 'both active: light green');
        self::assertSame(2, substr_count($html, '>Відключити</button>'));
        self::assertStringContainsString('form="router-toggle" name="router" value="branch"', $html);
        self::assertStringContainsString('<form method="post" action="/k7f3x/admin/settings/mikrotik" id="router-toggle"', $html);
        self::assertSame(2, substr_count($html, 'class="btn btn-outline-danger btn-sm ms-auto">Видалити</button>'), 'delete on each card');
        $before = $this->settings()->values();

        // disable branch: only the "disabled" mark changes
        $r = $this->request('POST', '/admin/settings/mikrotik', ['action' => 'toggle_router', 'router' => 'branch']);
        self::assertSame(303, $r->getStatusCode());
        $v = $this->settings()->values();
        $routers = json_decode($v['ROUTERS'], true);
        self::assertTrue($routers[1]['disabled']);
        self::assertEquals(['name' => 'branch', 'host' => '10.0.20.1', 'list' => 'VPN_branch', 'user' => 'portal_branch'],
            array_intersect_key($routers[1], ['name' => 1, 'host' => 1, 'list' => 1, 'user' => 1]), 'settings kept');
        self::assertArrayNotHasKey('disabled', $routers[0]);
        self::assertSame($before['ROUTER_PASSWORDS'], $v['ROUTER_PASSWORDS'], 'own password kept');

        $cfg = \VpnGw\Config::fromArray($this->cfgOverride + TestConfig::values(), $v);
        self::assertSame(['core', 'branch'], array_column($cfg->routers(), 'name'));
        self::assertSame(['core'], array_column($cfg->activeRouters(), 'name'), 'the portal works only with active ones');
        self::assertSame(['core'], array_keys($cfg->routerLists()));
        self::assertCount(1, \VpnGw\RouterOs\RouterOsFactory::fromConfig($cfg));

        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringContainsString('«branch» (10.0.20.1) відключено', $html);
        self::assertSame(1, substr_count($html, 'router-card router-off'), 'disabled: grey');
        self::assertSame(1, substr_count($html, 'router-card router-on'));
        self::assertStringContainsString('value="branch" class="btn btn-outline-success btn-sm">Включити</button>', $html);
        self::assertStringContainsString('disabled title="Єдиний увімкнений маршрутизатор відключити не можна">Відключити</button>', $html);
        self::assertStringContainsString('<tr class="table-secondary text-muted">', $html);
        $dash = self::body($this->request('GET', '/admin'));
        self::assertStringContainsString('+ 1 відключено', $dash);
        self::assertStringContainsString('10.0.20.1:', $dash);

        // the last active one can be neither disabled nor deleted
        foreach (['toggle_router', 'delete_router'] as $action) {
            $this->request('POST', '/admin/settings/mikrotik', ['action' => $action, 'router' => 'core']);
            self::assertSame($v['ROUTERS'], $this->settings()->values()['ROUTERS'], $action);
        }
        self::assertStringContainsString('єдиний увімкнений маршрутизатор', self::body($this->request('GET', '/admin/settings/mikrotik')));

        // editing a disabled router keeps it disabled
        $edit = self::body($this->request('GET', '/admin/routers/edit/branch'));
        self::assertStringContainsString('Маршрутизатор відключено', $edit);
        $r = $this->request('POST', '/admin/routers/edit/branch', ['action' => 'save', 'router' => [
            'name' => 'branch', 'host' => '10.0.20.1', 'version' => '6', 'port' => '8729', 'transport' => 'api', 'user' => 'portal_branch', 'list' => 'VPN_branch2', 'disabled' => '',
        ]]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $routers = json_decode($this->settings()->values()['ROUTERS'], true);
        self::assertSame('VPN_branch2', $routers[1]['list']);
        self::assertTrue($routers[1]['disabled']);

        // enable again
        $this->boot();
        $this->request('POST', '/admin/settings/mikrotik', ['action' => 'toggle_router', 'router' => 'branch']);
        $routers = json_decode($this->settings()->values()['ROUTERS'], true);
        self::assertArrayNotHasKey('disabled', $routers[1]);
        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringContainsString('«branch» (10.0.20.1) увімкнено', $html);
        self::assertSame(2, substr_count($html, 'router-card router-on'));

        // unknown router
        $this->request('POST', '/admin/settings/mikrotik', ['action' => 'toggle_router', 'router' => 'nope']);
        self::assertStringContainsString('Такого маршрутизатора вже немає', self::body($this->request('GET', '/admin/settings/mikrotik')));
    }

    public function testRoutersDisabledFlagValidation(): void
    {
        $base = $this->cfgOverride + TestConfig::values();
        $one = '{"name":"core","host":"10.0.10.1","version":7,"port":443,"transport":"api"';
        self::assertFalse(\VpnGw\Config::fromArray(['ROUTERS' => "[$one}]"] + $base)->routers()[0]['disabled']);
        foreach (["[$one,\"disabled\":true}]" => 'at least one router must be enabled', "[$one,\"disabled\":\"yes\"}]" => 'disabled must be true or false'] as $json => $msg) {
            try {
                \VpnGw\Config::fromArray(['ROUTERS' => $json] + $base)->validate();
                self::fail($json);
            } catch (\VpnGw\ConfigException $e) {
                self::assertStringContainsString($msg, $e->getMessage());
            }
        }
    }

    /** 0.8.2: a card per router; routers are deleted from the table under the cards. */
    public function testRouterCardsAndDeleteFromTable(): void
    {
        $this->login();
        $form = $this->mikrotikForm();
        $form['routers'][1] += ['transport' => 'api', 'user' => 'portal_branch', 'password' => 'Branch-Pass-1', 'list' => 'VPN_branch'];
        $form['routers'][0] += ['password' => 'Core-Pass-1'];
        self::assertSame(303, $this->request('POST', '/admin/settings/mikrotik', $form)->getStatusCode());
        $this->boot();

        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertSame(3, substr_count($html, 'card h-100 router-card'), 'two routers + «Додати маршрутизатор»');
        self::assertStringContainsString('href="/k7f3x/admin/routers/add"', $html);
        self::assertStringContainsString('href="/k7f3x/admin/routers/edit/branch"', $html);
        self::assertStringContainsString('<dd class="col-7 address-list"><strong>VPN_branch</strong></dd>', $html, 'own list, bold');
        self::assertStringContainsString('<dd class="col-7 address-list"><strong>Allowed_VPN_Users</strong> <span class="text-muted small fw-normal">(типовий)</span></dd>', $html);
        self::assertStringNotContainsString('name="routers[', $html, 'cards are read-only');
        self::assertStringContainsString('Підключені маршрутизатори', $html);
        self::assertStringContainsString('form="router-delete" name="router" value="branch"', $html);
        self::assertStringContainsString('<form method="post" action="/k7f3x/admin/settings/mikrotik" id="router-delete"', $html);
        self::assertStringNotContainsString('[delete]', $html, 'no delete checkboxes in the cards');

        $r = $this->request('POST', '/admin/settings/mikrotik', ['action' => 'delete_router', 'router' => 'branch']);
        self::assertSame(303, $r->getStatusCode());
        $v = $this->settings()->values();
        self::assertSame(['core'], array_column(json_decode($v['ROUTERS'], true), 'name'));
        self::assertSame('{"core":"Core-Pass-1"}', $v['ROUTER_PASSWORDS'], 'the deleted router\'s password goes with it');
        $this->boot();
        $html = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringContainsString('«branch» (10.0.20.1) видалено', $html);
        self::assertStringContainsString('disabled title="Єдиний увімкнений маршрутизатор видалити не можна"', $html);

        // the last one stays
        $r = $this->request('POST', '/admin/settings/mikrotik', ['action' => 'delete_router', 'router' => 'core']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame(['core'], array_column(json_decode($this->settings()->values()['ROUTERS'], true), 'name'));
        self::assertStringContainsString('Не можна видалити єдиний маршрутизатор', self::body($this->request('GET', '/admin/settings/mikrotik')));

        $this->request('POST', '/admin/settings/mikrotik', ['action' => 'delete_router', 'router' => 'nope']);
        self::assertStringContainsString('Такого маршрутизатора вже немає', self::body($this->request('GET', '/admin/settings/mikrotik')));
    }

    public function testDeleteRouterDropsItsHostKey(): void
    {
        $this->cfgOverride['ROUTERS'] = '[{"name":"core","host":"10.0.10.1","version":7,"port":8443},{"name":"old","host":"10.0.30.1","version":6,"port":22,"transport":"ssh"}]';
        $this->cfgOverride['MT_SSH_USER'] = 'vpn_portal';
        $this->cfgOverride['MT_SSH_PASS'] = 'ssh-pass';
        $this->cfgOverride['MT_SSH_HOSTKEYS'] = '{"old":"SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"}';
        $this->boot();
        $this->login();
        self::assertSame(303, $this->request('POST', '/admin/settings/mikrotik', ['action' => 'delete_router', 'router' => 'old'])->getStatusCode());
        $v = $this->settings()->values();
        self::assertSame('{}', $v['MT_SSH_HOSTKEYS']);
        self::assertSame(['core'], array_column(json_decode($v['ROUTERS'], true), 'name'));
    }

    public function testDeleteRouterNeedsCsrf(): void
    {
        $this->login();
        $r = $this->request('POST', '/admin/settings/mikrotik', ['action' => 'delete_router', 'router' => 'branch'], csrf: false);
        self::assertContains($r->getStatusCode(), [400, 403]);
        self::assertArrayNotHasKey('ROUTERS', $this->settings()->values());
    }

    /** 0.8.3: «Автентифікатори» — the registered bindings on a page of their own, with deletion. */
    public function testAuthenticatorsPage(): void
    {
        $now = $this->clock->now();
        $this->db->insertTotp('ivanenko', 'SECRET-BLOB-1', intdiv($now->getTimestamp(), 30), 'enroll', $now->modify('-3 days')->format(DATE_ATOM));
        $this->db->insertTotp('petrenko', 'y', 0, 'enroll', $now->modify('-1 day')->format(DATE_ATOM));
        $this->db->insertTotp('iv_anova', 'z', 0, 'enroll', $now->format(DATE_ATOM));
        $this->db->recordGrant('ivanenko', '198.51.100.7', $now->format(DATE_ATOM), $now->modify('+9 hours')->format(DATE_ATOM));
        $this->db->setUserEmail('ivanenko', 'ivan@company.ua', $now->format(DATE_ATOM));
        $this->login();
        self::assertStringContainsString('<td>ivan@company.ua</td>', self::body($this->request('GET', '/admin/authenticators')));

        $html = self::body($this->request('GET', '/admin'));
        self::assertStringContainsString('href="/k7f3x/admin/authenticators"', $html, 'menu item and dashboard tile');

        $html = self::body($this->request('GET', '/admin/authenticators'));
        self::assertStringContainsString('Усього: 3', $html);
        foreach (['ivanenko', 'petrenko', 'iv_anova'] as $u) {
            self::assertStringContainsString('name="username" value="' . $u . '"', $html);
        }
        self::assertStringContainsString(\VpnGw\Http\View::formatDate($now->modify('-3 days')->format(DATE_ATOM)), $html);
        self::assertSame(1, substr_count($html, 'badge bg-success">активний'), 'ivanenko has an open VPN access');
        self::assertStringNotContainsString('SECRET-BLOB-1', $html, 'secrets never reach the page');

        // search: "_" is a literal, not a LIKE wildcard
        $html = self::body($this->request('GET', '/admin/authenticators?q=iv_'));
        self::assertStringContainsString('Знайдено: 1 з 3', $html);
        self::assertStringContainsString('value="iv_anova"', $html);

        $r = $this->request('POST', '/admin/authenticators/delete', ['username' => 'petrenko']);
        self::assertSame(303, $r->getStatusCode());
        self::assertNull($this->db->findTotp('petrenko'));
        self::assertNotNull($this->db->findTotp('ivanenko'));
        $html = self::body($this->request('GET', '/admin/authenticators'));
        self::assertStringContainsString('Автентифікатор користувача petrenko видалено', $html);
        self::assertStringContainsString('Усього: 2', $html);

        $this->request('POST', '/admin/authenticators/delete', ['username' => 'petrenko']);
        self::assertStringContainsString('Такого автентифікатора вже немає', self::body($this->request('GET', '/admin/authenticators')));
    }

    public function testAuthenticatorDeleteNeedsLoginAndCsrf(): void
    {
        $this->db->insertTotp('ivanenko', 'x', 0, 'enroll', $this->clock->now()->format(DATE_ATOM));
        $r = $this->request('POST', '/admin/authenticators/delete', ['username' => 'ivanenko']);
        self::assertSame('/k7f3x/admin/login', $r->getHeaderLine('Location'));
        self::assertSame(404, $this->request('GET', '/admin/authenticators', ip: self::PUBLIC_IP)->getStatusCode());
        $this->login();
        $r = $this->request('POST', '/admin/authenticators/delete', ['username' => 'ivanenko'], csrf: false);
        self::assertContains($r->getStatusCode(), [400, 403]);
        self::assertNotNull($this->db->findTotp('ivanenko'));
    }

    /** 0.9: read-only router cards; each router is edited on its own page. */
    public function testRouterEditPage(): void
    {
        $this->login();
        $main = self::body($this->request('GET', '/admin/settings/mikrotik'));
        self::assertStringNotContainsString('типові облікові дані', $main, 'common credential groups are gone from the page');
        self::assertStringNotContainsString('name="MT_USER"', $main);
        self::assertStringNotContainsString('name="MT_SSH_KEY"', $main);
        self::assertStringNotContainsString('name="MT_CA_FILE"', $main);
        self::assertStringNotContainsString('name="MT_LIST"', $main);
        self::assertStringNotContainsString('Типовий address-list', $main);
        self::assertStringContainsString('name="MT_TIMEOUT"', $main);
        self::assertStringContainsString('name="PRIVATE_CLIENT_NETWORKS"', $main);

        $edit = self::body($this->request('GET', '/admin/routers/edit/core'));
        foreach (['router[name]', 'router[host]', 'router[version]', 'router[transport]', 'router[port]', 'router[user]', 'router[password]', 'router[list]'] as $f) {
            self::assertStringContainsString('name="' . $f . '"', $edit);
        }
        self::assertStringContainsString('value="10.0.10.1"', $edit);

        $r = $this->request('POST', '/admin/routers/edit/core', ['action' => 'save', 'router' => [
            'name' => 'core', 'host' => '10.0.10.1', 'version' => '7', 'transport' => 'api', 'port' => '8443',
            'user' => 'portal_core', 'password' => 'Core-Pass-9', 'list' => 'VPN_core',
        ]]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('/k7f3x/admin/settings/mikrotik', $r->getHeaderLine('Location'));
        $v = $this->settings()->values();
        $routers = json_decode($v['ROUTERS'], true);
        self::assertSame(['name' => 'core', 'host' => '10.0.10.1', 'version' => 7, 'port' => 8443, 'transport' => 'api', 'user' => 'portal_core', 'list' => 'VPN_core'], $routers[0]);
        self::assertSame(['name' => 'branch', 'host' => '10.0.20.1', 'version' => 6, 'port' => 8729, 'transport' => 'api'], $routers[1], 'other routers untouched');
        self::assertSame('{"core":"Core-Pass-9"}', $v['ROUTER_PASSWORDS']);
        $this->boot();
        self::assertStringContainsString('Маршрутизатор «core» збережено', self::body($this->request('GET', '/admin/settings/mikrotik')));

        // new address without the password again: refused, the form keeps what was typed
        $r = $this->request('POST', '/admin/routers/edit/core', ['action' => 'save', 'router' => [
            'name' => 'core', 'host' => '10.9.9.9', 'version' => '7', 'transport' => 'api', 'port' => '8443', 'user' => 'portal_core',
        ]]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('введіть його пароль ще раз', self::body($r));
        self::assertStringContainsString('value="10.9.9.9"', self::body($r));

        // add a router
        $r = $this->request('POST', '/admin/routers/add', ['action' => 'save', 'router' => [
            'name' => 'office2', 'host' => '10.0.40.1', 'version' => '6', 'transport' => 'ssh', 'port' => '22', 'user' => 'portal_o2', 'password' => 'O2-Pass-1',
        ]]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $v = $this->settings()->values();
        self::assertSame(['core', 'branch', 'office2'], array_column(json_decode($v['ROUTERS'], true), 'name'));
        self::assertSame('{"core":"Core-Pass-9","office2":"O2-Pass-1"}', $v['ROUTER_PASSWORDS']);
        $this->boot();

        // duplicate name and empty name are refused
        $r = $this->request('POST', '/admin/routers/add', ['action' => 'save', 'router' => ['name' => 'core', 'host' => '10.0.50.1', 'version' => '7', 'transport' => 'api', 'port' => '443']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('унікальними', self::body($r));
        $r = $this->request('POST', '/admin/routers/add', ['action' => 'save', 'router' => ['name' => '', 'host' => '10.0.50.1', 'version' => '7', 'transport' => 'api', 'port' => '443']]);
        self::assertSame(422, $r->getStatusCode());

        // rename keeps the password
        $r = $this->request('POST', '/admin/routers/edit/office2', ['action' => 'save', 'router' => [
            'name' => 'office3', 'host' => '10.0.40.1', 'version' => '6', 'transport' => 'ssh', 'port' => '22', 'user' => 'portal_o2',
        ]]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        self::assertSame('{"core":"Core-Pass-9","office3":"O2-Pass-1"}', $this->settings()->values()['ROUTER_PASSWORDS']);
        $this->boot();
        $r = $this->request('GET', '/admin/routers/edit/office2');
        self::assertSame(303, $r->getStatusCode(), 'unknown router: back to the list');
    }

    public function testMainPageSaveKeepsRoutersAndCommonCredentials(): void
    {
        $this->login();
        $before = $this->settings()->values();
        $r = $this->request('POST', '/admin/settings/mikrotik', [
            'action' => 'save', 'MT_TIMEOUT' => '08:00:00', 'PRIVATE_CLIENT_NETWORKS' => '',
        ]);
        self::assertSame(303, $r->getStatusCode(), self::body($r));
        $v = $this->settings()->values();
        self::assertSame('08:00:00', $v['MT_TIMEOUT']);
        foreach (['ROUTERS', 'ROUTER_PASSWORDS', 'MT_SSH_HOSTKEYS', 'MT_USER', 'MT_PASS', 'MT_SSH_USER', 'MT_SSH_PASS', 'MT_SSH_KEY', 'MT_LIST', 'MT_CA_FILE'] as $k) {
            self::assertSame($before[$k] ?? null, $v[$k] ?? null, "$k untouched");
        }
    }

    public function testRouterPageIsInternalAndNeedsCsrf(): void
    {
        self::assertSame(404, $this->request('GET', '/admin/routers/edit/core', ip: self::PUBLIC_IP)->getStatusCode());
        self::assertSame(404, $this->request('GET', '/admin/routers/add', ip: self::PUBLIC_IP)->getStatusCode());
        $this->login();
        $r = $this->request('POST', '/admin/routers/add', ['action' => 'save', 'router' => ['name' => 'x1', 'host' => '10.0.50.1', 'version' => '7', 'transport' => 'api', 'port' => '443']], csrf: false);
        self::assertContains($r->getStatusCode(), [400, 403]);
        self::assertArrayNotHasKey('ROUTERS', $this->settings()->values());
    }
}
