<?php

declare(strict_types=1);

namespace VpnGw\Auth;

final class AuthResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $username = '',
        public readonly ?string $mail = null,
        public readonly ?string $displayName = null,
    ) {
    }

    public static function ok(string $username, ?string $mail, ?string $displayName): self
    {
        return new self(true, 'ok', $username, $mail, $displayName);
    }

    /**
     * @param string $reason internal reason, only for the audit log:
     *   invalid_input | bad_credentials | not_allowed | expired | ldap_unavailable
     */
    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
