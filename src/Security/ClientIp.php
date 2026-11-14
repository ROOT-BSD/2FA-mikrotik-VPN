<?php

declare(strict_types=1);

namespace VpnGw\Security;

/**
 * Determines the client's public IPv4 address.
 *
 * X-Forwarded-For is honoured ONLY when the direct peer (REMOTE_ADDR) is a trusted
 * proxy, and then only its right-most entry (the one our proxy appended). The result
 * must be a single public IPv4 address: CIDR, ranges, IPv6, private and reserved
 * addresses are rejected so a forged header can never whitelist a whole network.
 */
final class ClientIp
{
    /**
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxies
     */
    public static function resolve(array $server, array $trustedProxies): ?string
    {
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? null;

        if (in_array($ip, $trustedProxies, true) && is_string($xff) && $xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            $ip = (string) end($parts);
        }

        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        return $valid === false ? null : $valid;
    }
}
