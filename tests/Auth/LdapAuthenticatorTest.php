<?php

declare(strict_types=1);

namespace VpnGw\Tests\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VpnGw\Auth\LdapAuthenticator;

/** Input validation happens before any LDAP call, so these tests need no domain controller. */
final class LdapAuthenticatorTest extends TestCase
{
    private function auth(): LdapAuthenticator
    {
        // Unroutable host: if validation were skipped, the test would hang/fail instead of returning invalid_input.
        return new LdapAuthenticator(['ldaps://192.0.2.1:636'], '@company.local', 'DC=company,DC=local', 'CN=VPN-Users,DC=company,DC=local', '/nonexistent');
    }

    #[DataProvider('badInput')]
    public function testRejectsBadInputWithoutContactingAd(string $user, string $pass): void
    {
        $r = $this->auth()->authenticate($user, $pass);
        self::assertFalse($r->ok);
        self::assertSame('invalid_input', $r->reason);
    }

    /** @return array<string, array{string, string}> */
    public static function badInput(): array
    {
        return [
            'empty password = anonymous bind' => ['ivanenko', ''],
            'too long password' => ['ivanenko', str_repeat('x', 257)],
            'empty login' => ['', 'pass'],
            'ldap wildcard' => ['admin*', 'pass'],
            'ldap filter injection' => ['a)(objectClass=*', 'pass'],
            'routeros injection' => ['x"; /system reset', 'pass'],
            'upn' => ['ivanenko@evil.com', 'pass'],
            'backslash' => ['DOMAIN\\ivanenko', 'pass'],
            'cyrillic' => ['іваненко', 'pass'],
            'too long login' => [str_repeat('a', 65), 'pass'],
        ];
    }

    public function testValidUsernames(): void
    {
        self::assertTrue(LdapAuthenticator::isValidUsername('ivanenko'));
        self::assertTrue(LdapAuthenticator::isValidUsername('i.ivanenko-2_x'));
    }

    public function testAccountExpires(): void
    {
        $now = 1_800_000_000;
        $toFiletime = static fn (int $unix): string => (string) (($unix + 11_644_473_600) * 10_000_000);
        self::assertFalse(LdapAuthenticator::isExpired('0', $now));
        self::assertFalse(LdapAuthenticator::isExpired('9223372036854775807', $now));
        self::assertFalse(LdapAuthenticator::isExpired($toFiletime($now + 86400), $now));
        self::assertTrue(LdapAuthenticator::isExpired($toFiletime($now - 86400), $now));
    }
}
