<?php

declare(strict_types=1);

namespace VpnGw\Tests\Settings;

use PHPUnit\Framework\TestCase;
use VpnGw\Auth\LdapAuthenticator;
use VpnGw\ConfigException;
use VpnGw\Security\Crypto;
use VpnGw\Settings\SettingsStore;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\TestConfig;

final class ConnectionSettingsTest extends TestCase
{
    private const SSH_ROUTERS = '[{"name":"old","host":"10.0.30.1","version":6,"port":22,"transport":"ssh"}]';

    public function testLdapModes(): void
    {
        self::assertTrue(TestConfig::make()->ldapVerifiesCertificate());
        $c = TestConfig::make(['LDAP_SECURITY' => 'ldaps-insecure', 'LDAP_CA_FILE' => '']);
        self::assertFalse($c->ldapVerifiesCertificate());
        TestConfig::make(['LDAP_SECURITY' => 'starttls-insecure', 'LDAP_HOSTS' => 'ldap://dc01.company.local:389', 'LDAP_CA_FILE' => '']);
        TestConfig::make(['LDAP_SECURITY' => 'plain', 'LDAP_HOSTS' => 'ldap://dc01.company.local,ldap://dc02.company.local', 'LDAP_CA_FILE' => '']);
        TestConfig::make(['LDAP_SECURITY' => 'starttls', 'LDAP_HOSTS' => 'ldap://dc01.company.local:389']);
        self::assertTrue(true);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalid(): array
    {
        return [
            'ldaps mode with ldap://' => [['LDAP_HOSTS' => 'ldap://dc01.company.local:389']],
            'starttls with ldaps://' => [['LDAP_SECURITY' => 'starttls']],
            'plain with ldaps://' => [['LDAP_SECURITY' => 'plain']],
            'verify without CA' => [['LDAP_CA_FILE' => '']],
            'starttls verify without CA' => [['LDAP_SECURITY' => 'starttls', 'LDAP_HOSTS' => 'ldap://dc01', 'LDAP_CA_FILE' => '']],
            'unknown mode' => [['LDAP_SECURITY' => 'tls']],
            'host with path' => [['LDAP_HOSTS' => 'ldaps://dc01/evil']],
            'ssh without user' => [['ROUTERS' => self::SSH_ROUTERS, 'MT_SSH_PASS' => 'x']],
            'ssh without password or key' => [['ROUTERS' => self::SSH_ROUTERS, 'MT_SSH_USER' => 'vpn_portal']],
            'ssh key garbage' => [['ROUTERS' => self::SSH_ROUTERS, 'MT_SSH_USER' => 'u', 'MT_SSH_KEY' => 'hello']],
            'bad transport' => [['ROUTERS' => '[{"name":"x","host":"10.0.0.1","version":7,"port":22,"transport":"telnet"}]']],
            'api router without CA' => [['MT_CA_FILE' => '']],
            'api router without user' => [['MT_USER' => '']],
            'hostkeys list' => [['MT_SSH_HOSTKEYS' => '["SHA256:x"]']],
            'hostkeys bad fp' => [['MT_SSH_HOSTKEYS' => '{"old":"MD5:aa"}']],
        ];
    }

    /** @param array<string, string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $override): void
    {
        $this->expectException(ConfigException::class);
        TestConfig::make($override);
    }

    public function testSshOnlyRoutersNeedNoApiSettings(): void
    {
        $c = TestConfig::make(['ROUTERS' => self::SSH_ROUTERS, 'MT_USER' => '', 'MT_PASS' => '', 'MT_CA_FILE' => '',
            'MT_SSH_USER' => 'vpn_portal', 'MT_SSH_KEY' => "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----\n",
            'MT_SSH_HOSTKEYS' => '{"old":"SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"}']);
        self::assertSame('ssh', $c->routers()[0]['transport']);
        self::assertSame(['old' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], $c->sshHostKeys());
        self::assertSame('api', TestConfig::make()->routers()[0]['transport'], 'old ROUTERS without "transport" = api');
    }

    public function testConnectionParametersAreEncryptedAtRest(): void
    {
        $db = Database::inMemory();
        $store = new SettingsStore($db, new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)));
        $values = ['ROUTERS' => self::SSH_ROUTERS, 'MT_SSH_USER' => 'vpn_portal', 'MT_SSH_PASS' => 'p@ss',
            'MT_SSH_KEY' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n", 'MT_SSH_KEY_PASS' => 'kp',
            'MT_SSH_HOSTKEYS' => '{}', 'MT_USER' => 'api_user', 'MT_LIST' => 'L'];
        $store->save($values, 'admin', 'now');
        foreach ($db->allSettings() as $key => $row) {
            if ($key === 'MT_LIST') {
                self::assertFalse($row['encrypted']);
                continue;
            }
            self::assertTrue($row['encrypted'], $key);
            self::assertStringNotContainsString($values[$key], $row['value'], $key);
        }
        self::assertSame($values, $store->values() + array_intersect_key($values, []));
    }

    public function testLegacyPlainValuesAreEncryptedByMigration(): void
    {
        $db = Database::inMemory();
        $db->saveSettings(['ROUTERS' => ['value' => self::SSH_ROUTERS, 'encrypted' => false], 'MT_LIST' => ['value' => 'L', 'encrypted' => false]], 'admin', 'then');
        $store = new SettingsStore($db, new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)));
        self::assertSame(['ROUTERS'], $store->encryptLegacyValues());
        self::assertTrue($db->allSettings()['ROUTERS']['encrypted']);
        self::assertFalse($db->allSettings()['MT_LIST']['encrypted']);
        self::assertSame(self::SSH_ROUTERS, $store->values()['ROUTERS']);
        self::assertSame([], $store->encryptLegacyValues());
    }

    public function testLdapTlsOptionsPerMode(): void
    {
        $verify = LdapAuthenticator::tlsOptions('ldaps', '/etc/ssl/ca.pem');
        self::assertSame(LDAP_OPT_X_TLS_DEMAND, $verify[LDAP_OPT_X_TLS_REQUIRE_CERT]);
        self::assertSame('/etc/ssl/ca.pem', $verify[LDAP_OPT_X_TLS_CACERTFILE]);
        self::assertSame($verify, LdapAuthenticator::tlsOptions('starttls', '/etc/ssl/ca.pem'));
        self::assertSame([LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_NEVER], LdapAuthenticator::tlsOptions('ldaps-insecure', ''));
        self::assertSame([LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_NEVER], LdapAuthenticator::tlsOptions('starttls-insecure', ''));
        self::assertSame([], LdapAuthenticator::tlsOptions('plain', ''));
        self::assertTrue(LdapAuthenticator::usesStartTls('starttls-insecure'));
        self::assertFalse(LdapAuthenticator::usesStartTls('ldaps'));
    }
}
