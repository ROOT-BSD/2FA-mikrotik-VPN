<?php

declare(strict_types=1);

namespace VpnGw\Auth;

use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Psr\Clock\ClockInterface;
use VpnGw\Security\Crypto;
use VpnGw\Storage\Database;

/**
 * Second factor: TOTP (RFC 6238) compatible with Microsoft Authenticator —
 * SHA1, 6 digits, 30 s period, 160-bit secret. Secrets are stored encrypted;
 * each time-step can be used only once (atomic last_step update).
 */
final class TotpService
{
    public const ISSUER = 'VPN-GW';
    public const PERIOD = 30;
    /** Accept the previous and next time-step to tolerate small clock drift. */
    private const DRIFT_STEPS = [0, -1, 1];

    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isEnrolled(string $username): bool
    {
        return $this->db->findTotp($username) !== null;
    }

    /** @return array{secret: string, uri: string} */
    public function newSecret(string $username): array
    {
        if ($username === '') {
            throw new \InvalidArgumentException('Empty username');
        }
        $secret = Base32::encodeUpperUnpadded(random_bytes(20));
        if ($secret === '') {
            throw new \RuntimeException('Secret generation failed');
        }
        $totp = TOTP::createFromSecret($secret, $this->clock);
        $totp->setLabel($username);
        $totp->setIssuer(self::ISSUER);
        return ['secret' => $secret, 'uri' => $totp->getProvisioningUri()];
    }

    /** Stores the secret only if the user proved possession with a valid first code. */
    public function confirmEnrollment(string $username, #[\SensitiveParameter] string $secret, string $code, string $createdBy): bool
    {
        if ($this->isEnrolled($username)) {
            return false;
        }
        $step = $this->matchStep($secret, $code, 0);
        if ($step === null) {
            return false;
        }
        $this->db->insertTotp(
            $username,
            $this->crypto->encrypt($secret),
            $step,
            $createdBy,
            $this->clock->now()->format(DATE_ATOM),
        );
        return true;
    }

    public function verify(string $username, string $code): bool
    {
        $row = $this->db->findTotp($username);
        if ($row === null) {
            return false;
        }
        $step = $this->matchStep($this->crypto->decrypt($row['secret_enc']), $code, $row['last_step']);
        return $step !== null && $this->db->advanceTotpStep($username, $step);
    }

    public function reset(string $username): bool
    {
        return $this->db->deleteTotp($username);
    }

    /** "JBSWY3DPEHPK3PXP..." -> "JBSW Y3DP EHPK ..." for manual entry. */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function normalizeCode(string $code): string
    {
        return (string) preg_replace('/\s+/', '', $code);
    }

    private function matchStep(#[\SensitiveParameter] string $secret, string $code, int $lastStep): ?int
    {
        $code = self::normalizeCode($code);
        if ($secret === '' || preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        foreach (self::DRIFT_STEPS as $drift) {
            $ts = $now + $drift * self::PERIOD;
            $step = intdiv($ts, self::PERIOD);
            if ($ts < 0 || $step <= $lastStep) {
                continue;
            }
            if (hash_equals($totp->at($ts), $code)) {
                return $step;
            }
        }
        return null;
    }
}
