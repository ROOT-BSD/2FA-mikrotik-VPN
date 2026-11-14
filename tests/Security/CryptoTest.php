<?php

declare(strict_types=1);

namespace VpnGw\Tests\Security;

use PHPUnit\Framework\TestCase;
use VpnGw\Security\Crypto;

final class CryptoTest extends TestCase
{
    private function crypto(string $k = "\x01"): Crypto
    {
        return new Crypto(str_repeat($k, 32), str_repeat("\x02", 32));
    }

    public function testRoundTripAndRandomNonce(): void
    {
        $c = $this->crypto();
        $a = $c->encrypt('JBSWY3DPEHPK3PXP');
        $b = $c->encrypt('JBSWY3DPEHPK3PXP');
        self::assertNotSame($a, $b);
        self::assertSame('JBSWY3DPEHPK3PXP', $c->decrypt($a));
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $a);
    }

    public function testWrongKeyFails(): void
    {
        $blob = $this->crypto("\x01")->encrypt('secret');
        $this->expectException(\RuntimeException::class);
        $this->crypto("\x09")->decrypt($blob);
    }

    public function testTamperingFails(): void
    {
        $blob = $this->crypto()->encrypt('secret');
        $blob[strlen($blob) - 1] = chr(ord($blob[strlen($blob) - 1]) ^ 1);
        $this->expectException(\RuntimeException::class);
        $this->crypto()->decrypt($blob);
    }

    public function testRejectsShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto('short', str_repeat("\x02", 32));
    }

    public function testHmacIsDeterministic(): void
    {
        self::assertSame($this->crypto()->hmac('123456'), $this->crypto()->hmac('123456'));
        self::assertNotSame($this->crypto()->hmac('123456'), $this->crypto()->hmac('123457'));
    }
}
