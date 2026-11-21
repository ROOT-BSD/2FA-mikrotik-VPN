<?php

declare(strict_types=1);

namespace VpnGw\Auth;

/**
 * First factor: bind to Active Directory as the user, then confirm that the account is
 * in the VPN group (nested groups included), enabled and not expired.
 *
 * Connection modes (LDAP_SECURITY): ldaps / starttls verify the DC certificate against
 * LDAP_CA_FILE; *-insecure encrypt without checking the certificate; plain sends the
 * password unencrypted. See Config::LDAP_MODES.
 */
final class LdapAuthenticator implements Authenticator
{
    /** LDAP_MATCHING_RULE_IN_CHAIN — membership through nested groups. */
    private const IN_CHAIN = '1.2.840.113556.1.4.1941';
    /** LDAP_MATCHING_RULE_BIT_AND — for userAccountControl flags. */
    private const BIT_AND = '1.2.840.113556.1.4.803';
    private const UAC_ACCOUNTDISABLE = 2;
    private const LDAP_SERVER_DOWN = -1;

    /**
     * @param list<string> $hosts ldaps:// or ldap:// URIs, tried in order
     * @param string $vpnGroup group DN (CN=...,DC=...) or a plain group name looked up in AD
     */
    public function __construct(
        private readonly array $hosts,
        private readonly string $upnSuffix,
        private readonly string $baseDn,
        private readonly string $vpnGroup,
        private readonly string $caFile,
        private readonly string $mode = 'ldaps',
    ) {
    }

    /**
     * Accepts "user" and "user@domain" (only our own domain) and returns "user" in lower case.
     * Anything else is returned unchanged and is then refused by isValidUsername().
     */
    public static function normalizeUsername(string $raw, string $domain): string
    {
        $raw = strtolower(trim($raw));
        $at = strrpos($raw, '@');
        if ($at === false) {
            return $raw;
        }
        return substr($raw, $at + 1) === strtolower($domain) ? substr($raw, 0, $at) : $raw;
    }

    private static function looksLikeDn(string $group): bool
    {
        return stripos($group, 'CN=') === 0 && str_contains($group, ',');
    }

    /**
     * TLS options for the connection, by mode (option constant => value).
     *
     * @return array<int, int|string>
     */
    public static function tlsOptions(string $mode, string $caFile): array
    {
        return match ($mode) {
            'ldaps', 'starttls' => [LDAP_OPT_X_TLS_CACERTFILE => $caFile, LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_DEMAND],
            'ldaps-insecure', 'starttls-insecure' => [LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_NEVER],
            'plain' => [],
            default => throw new \InvalidArgumentException("Unknown LDAP mode $mode"),
        };
    }

    public static function usesStartTls(string $mode): bool
    {
        return str_starts_with($mode, 'starttls');
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
            $tls = self::tlsOptions($this->mode, $this->caFile);
            foreach ($tls as $opt => $value) {
                ldap_set_option($conn, $opt, $value);
            }
            // Apply the TLS options to this connection. The constant exists only when PHP is
            // built against OpenLDAP (Debian is); without it the global options above still apply.
            if ($tls !== [] && defined('LDAP_OPT_X_TLS_NEWCTX')) {
                ldap_set_option($conn, (int) constant('LDAP_OPT_X_TLS_NEWCTX'), 0);
            }
            if (self::usesStartTls($this->mode) && !@ldap_start_tls($conn)) {
                @ldap_unbind($conn);
                continue; // no TLS with this DC (or untrusted certificate): never fall back to clear text
            }

            if (!@ldap_bind($conn, $username . $this->upnSuffix, $password)) {
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                if ($errno === self::LDAP_SERVER_DOWN) {
                    continue; // try the next domain controller
                }
                return AuthResult::fail('bad_credentials');
            }

            $groupDn = self::looksLikeDn($this->vpnGroup) ? $this->vpnGroup : $this->findGroupDn($conn);
            if ($groupDn === null) {
                @ldap_unbind($conn);
                return AuthResult::fail('group_not_found');
            }
            $filter = sprintf(
                '(&(objectClass=user)(sAMAccountName=%s)(memberOf:%s:=%s)(!(userAccountControl:%s:=%d)))',
                ldap_escape($username, '', LDAP_ESCAPE_FILTER),
                self::IN_CHAIN,
                ldap_escape($groupDn, '', LDAP_ESCAPE_FILTER),
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

    /** DN of the VPN group by its name (sAMAccountName or CN), searched as the logged-in user. */
    private function findGroupDn(\LDAP\Connection $conn): ?string
    {
        $name = ldap_escape($this->vpnGroup, '', LDAP_ESCAPE_FILTER);
        $res = @ldap_search($conn, $this->baseDn, "(&(objectClass=group)(|(sAMAccountName=$name)(cn=$name)))", ['dn'], 0, 2);
        $entries = $res instanceof \LDAP\Result ? ldap_get_entries($conn, $res) : false;
        if (!is_array($entries) || ($entries['count'] ?? 0) !== 1 || !is_string($entries[0]['dn'] ?? null)) {
            return null;
        }
        return $entries[0]['dn'];
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
