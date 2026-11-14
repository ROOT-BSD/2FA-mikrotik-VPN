<?php

declare(strict_types=1);

namespace VpnGw\Auth;

/**
 * First factor: bind to Active Directory over LDAPS as the user, then confirm that
 * the account is in the VPN group (nested groups included), enabled and not expired.
 */
final class LdapAuthenticator implements Authenticator
{
    /** LDAP_MATCHING_RULE_IN_CHAIN — membership through nested groups. */
    private const IN_CHAIN = '1.2.840.113556.1.4.1941';
    /** LDAP_MATCHING_RULE_BIT_AND — for userAccountControl flags. */
    private const BIT_AND = '1.2.840.113556.1.4.803';
    private const UAC_ACCOUNTDISABLE = 2;
    private const LDAP_SERVER_DOWN = -1;

    /** @param list<string> $hosts ldaps:// URIs, tried in order */
    public function __construct(
        private readonly array $hosts,
        private readonly string $upnSuffix,
        private readonly string $baseDn,
        private readonly string $vpnGroupDn,
        private readonly string $caFile,
    ) {
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username) === 1;
    }

    public function authenticate(string $username, #[\SensitiveParameter] string $password): AuthResult
    {
        // Empty password = anonymous bind, which AD answers with "success". Reject before any LDAP call.
        if (!self::isValidUsername($username) || $password === '' || strlen($password) > 256) {
            return AuthResult::fail('invalid_input');
        }
        if (!function_exists('ldap_connect')) {
            return AuthResult::fail('ldap_unavailable');
        }

        foreach ($this->hosts as $host) {
            $conn = @ldap_connect($host);
            if ($conn === false) {
                continue;
            }
            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
            ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 5);
            ldap_set_option($conn, LDAP_OPT_X_TLS_CACERTFILE, $this->caFile);
            ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            // Apply the TLS options to this connection. The constant exists only when PHP is
            // built against OpenLDAP (Debian is); without it the global options above still apply.
            if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
                ldap_set_option($conn, (int) constant('LDAP_OPT_X_TLS_NEWCTX'), 0);
            }

            if (!@ldap_bind($conn, $username . $this->upnSuffix, $password)) {
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                if ($errno === self::LDAP_SERVER_DOWN) {
                    continue; // try the next domain controller
                }
                return AuthResult::fail('bad_credentials');
            }

            $filter = sprintf(
                '(&(objectClass=user)(sAMAccountName=%s)(memberOf:%s:=%s)(!(userAccountControl:%s:=%d)))',
                ldap_escape($username, '', LDAP_ESCAPE_FILTER),
                self::IN_CHAIN,
                ldap_escape($this->vpnGroupDn, '', LDAP_ESCAPE_FILTER),
                self::BIT_AND,
                self::UAC_ACCOUNTDISABLE,
            );
            $res = @ldap_search($conn, $this->baseDn, $filter, ['sAMAccountName', 'mail', 'displayName', 'accountExpires'], 0, 2);
            $entries = $res instanceof \LDAP\Result ? ldap_get_entries($conn, $res) : false;
            @ldap_unbind($conn);

            if (!is_array($entries) || ($entries['count'] ?? 0) !== 1) {
                return AuthResult::fail('not_allowed');
            }
            $e = $entries[0];
            if (self::isExpired((string) ($e['accountexpires'][0] ?? '0'))) {
                return AuthResult::fail('expired');
            }
            return AuthResult::ok(
                strtolower((string) ($e['samaccountname'][0] ?? $username)),
                isset($e['mail'][0]) ? (string) $e['mail'][0] : null,
                isset($e['displayname'][0]) ? (string) $e['displayname'][0] : null,
            );
        }

        return AuthResult::fail('ldap_unavailable');
    }

    /**
     * accountExpires is a Windows FILETIME (100 ns ticks since 1601-01-01);
     * 0 and 0x7FFFFFFFFFFFFFFF mean "never".
     */
    public static function isExpired(string $accountExpires, ?int $now = null): bool
    {
        if (!ctype_digit($accountExpires) || $accountExpires === '0' || $accountExpires === '9223372036854775807') {
            return false;
        }
        $unix = intdiv((int) $accountExpires, 10_000_000) - 11_644_473_600;
        return $unix <= ($now ?? time());
    }
}
