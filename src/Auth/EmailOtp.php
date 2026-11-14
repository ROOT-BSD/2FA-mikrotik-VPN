<?php

declare(strict_types=1);

namespace VpnGw\Auth;

use Psr\Clock\ClockInterface;
use VpnGw\Http\Session;
use VpnGw\Security\Crypto;

/**
 * Backup second factor: 6-digit code by e-mail (address from AD only).
 * Only an HMAC of the code is kept in the session; the code is single-use, valid 5 min.
 */
final class EmailOtp
{
    public const TTL = 300;
    private const KEY = 'email_otp';

    public function __construct(
        private readonly Session $session,
        private readonly Crypto $crypto,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return string the code to send (never store or log it) */
    public function issue(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->session->set(self::KEY, [
            'hmac' => $this->crypto->hmac($code),
            'ts' => $this->clock->now()->getTimestamp(),
        ]);
        return $code;
    }

    public function isPending(): bool
    {
        $otp = $this->session->get(self::KEY);
        return is_array($otp) && $this->clock->now()->getTimestamp() - (int) ($otp['ts'] ?? 0) <= self::TTL;
    }

    public function verify(string $code): bool
    {
        $otp = $this->session->get(self::KEY);
        if (!is_array($otp) || !is_string($otp['hmac'] ?? null) || !is_int($otp['ts'] ?? null)) {
            return false;
        }
        if ($this->clock->now()->getTimestamp() - $otp['ts'] > self::TTL) {
            $this->session->remove(self::KEY);
            return false;
        }
        $ok = hash_equals($otp['hmac'], $this->crypto->hmac(TotpService::normalizeCode($code)));
        if ($ok) {
            $this->session->remove(self::KEY);
        }
        return $ok;
    }
}
