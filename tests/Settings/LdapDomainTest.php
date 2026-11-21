<?php

declare(strict_types=1);

namespace VpnGw\Tests\Settings;

use PHPUnit\Framework\TestCase;
use VpnGw\Auth\LdapAuthenticator;
use VpnGw\ConfigException;
use VpnGw\Tests\Support\TestConfig;

final class LdapDomainTest extends TestCase
{
    /** @return array<string, string> */
    private static function domainOnly(): array
    {
        return ['LDAP_DOMAIN' => 'NERC.gov.ua', 'LDAP_VPN_GROUP' => 'VPN-Users',
            'LDAP_UPN_SUFFIX' => '', 'LDAP_BASE_DN' => '', 'LDAP_VPN_GROUP_DN' => ''];
    }

    public function testDomainDerivesUpnAndBaseDn(): void
    {
        $c = TestConfig::make(self::domainOnly());
        self::assertSame('nerc.gov.ua', $c->ldapDomain());
        self::assertSame('@nerc.gov.ua', $c->ldapUpnSuffix());
        self::assertSame('DC=nerc,DC=gov,DC=ua', $c->ldapBaseDn());
        self::assertSame('VPN-Users', $c->ldapGroupName());
        self::assertNull($c->ldapGroupDn());
    }

    public function testDomainWinsOverStaleDnValues(): void
    {
        $c = TestConfig::make(['LDAP_DOMAIN' => 'nerc.gov.ua', 'LDAP_VPN_GROUP' => 'VPN-Users']); // .env still has company.local DNs
        self::assertSame('DC=nerc,DC=gov,DC=ua', $c->ldapBaseDn());
        self::assertSame('@nerc.gov.ua', $c->ldapUpnSuffix());
        self::assertNull($c->ldapGroupDn());
    }

    public function testLegacyDnSettingsStillWork(): void
    {
        $c = TestConfig::make();
        self::assertSame('company.local', $c->ldapDomain());
        self::assertSame('DC=company,DC=local', $c->ldapBaseDn());
        self::assertSame('CN=VPN-Users,OU=Groups,DC=company,DC=local', $c->ldapGroupDn());
        self::assertNull($c->ldapGroupName());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalid(): array
    {
        return [
            'no domain at all' => [['LDAP_DOMAIN' => '', 'LDAP_UPN_SUFFIX' => '', 'LDAP_BASE_DN' => '']],
            'domain without dot' => [['LDAP_DOMAIN' => 'nerc']],
            'domain with DN syntax' => [['LDAP_DOMAIN' => 'DC=nerc,DC=gov,DC=ua']],
            'no group' => [['LDAP_DOMAIN' => 'nerc.gov.ua', 'LDAP_VPN_GROUP' => '', 'LDAP_VPN_GROUP_DN' => '']],
            'group with filter chars' => [['LDAP_DOMAIN' => 'nerc.gov.ua', 'LDAP_VPN_GROUP' => 'VPN*)(cn=*']],
        ];
    }

    /** @param array<string, string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $override): void
    {
        $this->expectException(ConfigException::class);
        TestConfig::make($override);
    }

    public function testUsernameFormats(): void
    {
        self::assertSame('ivanenko', LdapAuthenticator::normalizeUsername('Ivanenko', 'nerc.gov.ua'));
        self::assertSame('ivanenko', LdapAuthenticator::normalizeUsername(' ivanenko@NERC.gov.ua ', 'nerc.gov.ua'));
        self::assertSame('ivanenko@evil.com', LdapAuthenticator::normalizeUsername('ivanenko@evil.com', 'nerc.gov.ua'),
            'another domain is not stripped, so it fails validation');
        self::assertFalse(LdapAuthenticator::isValidUsername(LdapAuthenticator::normalizeUsername('ivanenko@evil.com', 'nerc.gov.ua')));
        self::assertSame('a@b', LdapAuthenticator::normalizeUsername('a@b@nerc.gov.ua', 'nerc.gov.ua'));
        self::assertFalse(LdapAuthenticator::isValidUsername('a@b'));
    }
}
