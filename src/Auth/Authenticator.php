<?php

declare(strict_types=1);

namespace VpnGw\Auth;

interface Authenticator
{
    public function authenticate(string $username, #[\SensitiveParameter] string $password): AuthResult;
}
