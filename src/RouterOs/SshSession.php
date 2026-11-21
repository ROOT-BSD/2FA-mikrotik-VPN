<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

/** Minimal SSH session used by SshClient (replaceable in tests). */
interface SshSession
{
    /** Connects (key exchange only) and returns the server host key fingerprint "SHA256:...". */
    public function hostKeyFingerprint(): string;

    /** Password and/or private-key login; false when the router rejects the credentials. */
    public function login(string $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $privateKey, #[\SensitiveParameter] string $keyPassphrase): bool;

    /** Runs one command (exec channel) and returns its output. */
    public function exec(string $command): string;

    public function disconnect(): void;
}
