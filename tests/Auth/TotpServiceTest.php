<?php

declare(strict_types=1);

namespace VpnGw\Tests\Auth;

use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use VpnGw\Auth\TotpService;
use VpnGw\Security\Crypto;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FrozenClock;

final class TotpServiceTest extends TestCase
{
    private FrozenClock $clock;
    private Database $db;
    private TotpService $svc;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1_800_000_010);
        $this->db = Database::inMemory();
        $this->svc = new TotpService($this->db, new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)), $this->clock);
    }

    private function codeAt(string $secret, int $ts): string
    {
        /** @var non-empty-string $secret */
        return TOTP::createFromSecret($secret, $this->clock)->at($ts);
    }

    /** @return string enrolled secret */
    private function enroll(string $user = 'ivanenko'): string
    {
        $secret = $this->svc->newSecret($user)['secret'];
        self::assertTrue($this->svc->confirmEnrollment($user, $secret, $this->codeAt($secret, $this->clock->ts()), 'self'));
        return $secret;
    }

    public function testProvisioningUriIsMicrosoftAuthenticatorCompatible(): void
    {
        $new = $this->svc->newSecret('ivanenko');
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $new['secret'], '160-bit Base32 secret');
        self::assertStringStartsWith('otpauth://totp/VPN-GW%3Aivanenko?', $new['uri']);
        self::assertStringContainsString('issuer=VPN-GW', $new['uri']);
        self::assertStringContainsString('secret=' . $new['secret'], $new['uri']);
    }

    public function testEnrollmentRequiresValidFirstCode(): void
    {
        $secret = $this->svc->newSecret('ivanenko')['secret'];
        self::assertFalse($this->svc->confirmEnrollment('ivanenko', $secret, '000000', 'self'));
        self::assertFalse($this->svc->isEnrolled('ivanenko'));
    }

    public function testSecretIsStoredEncrypted(): void
    {
        $secret = $this->enroll();
        $row = $this->db->findTotp('ivanenko');
        self::assertNotNull($row);
        self::assertStringNotContainsString($secret, $row['secret_enc']);
    }

    public function testCannotEnrollTwice(): void
    {
        $this->enroll();
        $secret2 = $this->svc->newSecret('ivanenko')['secret'];
        self::assertFalse($this->svc->confirmEnrollment('ivanenko', $secret2, $this->codeAt($secret2, $this->clock->ts()), 'self'));
    }

    public function testVerifyAcceptsCurrentCodeOnlyOnce(): void
    {
        $secret = $this->enroll();
        $this->clock->advance(30);
        $code = $this->codeAt($secret, $this->clock->ts());
        self::assertTrue($this->svc->verify('ivanenko', $code));
        self::assertFalse($this->svc->verify('ivanenko', $code), 'replay must be rejected');
    }

    public function testEnrollmentCodeCannotBeReusedForLogin(): void
    {
        $secret = $this->enroll();
        self::assertFalse($this->svc->verify('ivanenko', $this->codeAt($secret, $this->clock->ts())));
    }

    public function testToleratesOneStepOfDriftButNotTwo(): void
    {
        $secret = $this->enroll();
        $this->clock->advance(300);
        $now = $this->clock->ts();
        self::assertFalse($this->svc->verify('ivanenko', $this->codeAt($secret, $now + 60)), '+2 steps');
        self::assertTrue($this->svc->verify('ivanenko', $this->codeAt($secret, $now - 30)), '-1 step');
        self::assertTrue($this->svc->verify('ivanenko', $this->codeAt($secret, $now + 30)), '+1 step');
    }

    public function testAcceptsCodeWithSpaces(): void
    {
        $secret = $this->enroll();
        $this->clock->advance(60);
        $code = $this->codeAt($secret, $this->clock->ts());
        self::assertTrue($this->svc->verify('ivanenko', substr($code, 0, 3) . ' ' . substr($code, 3)));
    }

    public function testRejectsMalformedCodesAndUnknownUsers(): void
    {
        $this->enroll();
        self::assertFalse($this->svc->verify('ivanenko', '12345'));
        self::assertFalse($this->svc->verify('ivanenko', 'abcdef'));
        self::assertFalse($this->svc->verify('nobody', '123456'));
    }

    public function testResetAndFormat(): void
    {
        $this->enroll();
        self::assertTrue($this->svc->reset('ivanenko'));
        self::assertFalse($this->svc->isEnrolled('ivanenko'));
        self::assertSame('ABCD EFGH IJ', TotpService::formatSecret('ABCDEFGHIJ'));
    }
}
