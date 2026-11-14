<?php

declare(strict_types=1);

namespace VpnGw\Security;

/** IPv4 CIDR helpers. */
final class Cidr
{
    public static function isValid(string $cidr): bool
    {
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)) {
            return false;
        }
        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) $m[2] <= 32;
    }

    public static function contains(string $cidr, string $ip): bool
    {
        if (!self::isValid($cidr) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        [$net, $bits] = explode('/', $cidr);
        $bits = (int) $bits;
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }

    /** @param list<string> $cidrs */
    public static function containsAny(array $cidrs, string $ip): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::contains($cidr, $ip)) {
                return true;
            }
        }
        return false;
    }
}
