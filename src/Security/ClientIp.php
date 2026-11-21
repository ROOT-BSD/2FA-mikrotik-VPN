<?php

declare(strict_types=1);

namespace VpnGw\Security;

/**
 * Determines the client's public IPv4 address.
 *
 * X-Forwarded-For is honoured ONLY when the direct peer (REMOTE_ADDR) is a trusted
 * proxy, and then only its right-most entry (the one our proxy appended). The result
 * must be a single IPv4 address: CIDR, ranges and IPv6 are rejected. Private and
 * reserved addresses are rejected too, except inside the networks the administrator
 * explicitly allowed (PRIVATE_CLIENT_NETWORKS, e.g. an office LAN used for tests or
 * clients reaching the VPN over an internal link).
 */
final class ClientIp
{
    /**
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxies
     * @param list<string> $privateNetworks CIDRs where non-public client addresses are accepted
     */
    public static function resolve(array $server, array $trustedProxies, array $privateNetworks = []): ?string
    {
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? null;

        if (in_array($ip, $trustedProxies, true) && is_string($xff) && $xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            $ip = (string) end($parts);
        }

        return self::isAcceptable($ip, $privateNetworks) ? $ip : null;
    }

    /**
     * A single IPv4 address that is public, or private but inside an allowed network.
     *
     * @param list<string> $privateNetworks
     */
    public static function isAcceptable(string $ip, array $privateNetworks = []): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return true;
        }
        // never: unspecified, loopback, broadcast — whatever the allowed networks say
        if (in_array(explode('.', $ip)[0], ['0', '127'], true) || $ip === '255.255.255.255') {
            return false;
        }
        return $privateNetworks !== [] && Cidr::containsAny($privateNetworks, $ip);
    }
}
