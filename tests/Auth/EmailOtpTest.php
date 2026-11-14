<?php

declare(strict_types=1);

namespace VpnGw\Tests\Auth;

use PHPUnit\Framework\TestCase;
use VpnGw\Auth\EmailOtp;
use VpnGw\Http\Session;
use VpnGw\Security\Crypto;
use VpnGw\Tests\Support\FrozenClock;

final class EmailOtpTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];
    private FrozenClock $clock;
    private EmailOtp $otp;

    protected function setUp(): void
    {
        $this->store = [];
        $this->clock = new FrozenClock();
        $this->otp = new EmailOtp(new Session($this->store), new Crypto(str_repeat("\x01", 32), str_repeat("\x02", 32)), $this->clock);
    }

    public function testCodeIsSixDigitsAndNotStoredInPlainText(): void
    {
        $code = $this->otp->issue();
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertStringNotContainsString($code, serialize($this->store));
        self::assertTrue($this->otp->isPending());
    }

    public function testSingleUse(): void
    {
        $code = $this->otp->issue();
        self::assertTrue($this->otp->verify($code));
        self::assertFalse($this->otp->verify($code));
    }

    public function testExpiresAfterFiveMinutes(): void
    {
        $code = $this->otp->issue();
        $this->clock->advance(EmailOtp::TTL + 1);
        self::assertFalse($this->otp->isPending());
        self::assertFalse($this->otp->verify($code));
    }

    public function testWrongCode(): void
    {
        $code = $this->otp->issue();
        $wrong = $code === '000000' ? '000001' : '000000';
        self::assertFalse($this->otp->verify($wrong));
        self::assertTrue($this->otp->verify($code), 'a wrong attempt does not consume the code');
    }
}
