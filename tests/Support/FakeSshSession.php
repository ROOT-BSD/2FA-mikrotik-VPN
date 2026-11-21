<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

use VpnGw\RouterOs\SshSession;

/** Records commands; answers from a queue (default: empty output = success). */
final class FakeSshSession implements SshSession
{
    /** @var list<string> */
    public array $commands = [];
    /** @var list<string> */
    public array $answers = [];
    public ?array $loginWith = null;
    public bool $connected = false;

    public function __construct(
        public string $fingerprint = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        public bool $loginOk = true,
    ) {
    }

    public function hostKeyFingerprint(): string
    {
        $this->connected = true;
        return $this->fingerprint;
    }

    public function login(string $user, string $password, string $privateKey, string $keyPassphrase): bool
    {
        $this->loginWith = [$user, $password, $privateKey, $keyPassphrase];
        return $this->loginOk;
    }

    public function exec(string $command): string
    {
        $this->commands[] = $command;
        return array_shift($this->answers) ?? '';
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }
}
