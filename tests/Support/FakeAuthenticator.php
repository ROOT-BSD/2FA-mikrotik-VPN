<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

use VpnGw\Auth\Authenticator;
use VpnGw\Auth\AuthResult;

/** Accepts exactly one login/password pair. */
final class FakeAuthenticator implements Authenticator
{
    public int $calls = 0;

    public function __construct(
        private readonly string $user = 'ivanenko',
        private readonly string $pass = 'Correct-Horse-1',
        private readonly ?string $mail = 'ivanenko@company.ua',
    ) {
    }

    public function authenticate(string $username, string $password): AuthResult
    {
        $this->calls++;
        return $username === $this->user && $password === $this->pass
            ? AuthResult::ok($this->user, $this->mail, 'Іваненко Іван')
            : AuthResult::fail('bad_credentials');
    }
}
