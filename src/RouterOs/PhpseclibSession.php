<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/** SshSession on phpseclib 3 (pure PHP, no ext-ssh2 needed). */
final class PhpseclibSession implements SshSession
{
    private ?SSH2 $ssh = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout = 8,
    ) {
    }

    public function hostKeyFingerprint(): string
    {
        $key = $this->ssh()->getServerPublicHostKey();
        if (!is_string($key) || !str_contains($key, ' ')) {
            throw new RouterOsException('SSH: no host key received');
        }
        return self::fingerprint($key);
    }

    /** OpenSSH-style SHA-256 fingerprint of "type base64-blob". */
    public static function fingerprint(string $openSshPublicKey): string
    {
        $blob = base64_decode(explode(' ', trim($openSshPublicKey))[1] ?? '', true);
        if ($blob === false || $blob === '') {
            throw new RouterOsException('SSH: malformed host key');
        }
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public function login(string $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $privateKey, #[\SensitiveParameter] string $keyPassphrase): bool
    {
        $ssh = $this->ssh();
        if ($privateKey !== '') {
            try {
                $key = $keyPassphrase === '' ? PublicKeyLoader::loadPrivateKey($privateKey) : PublicKeyLoader::loadPrivateKey($privateKey, $keyPassphrase);
            } catch (\Throwable $e) {
                throw new RouterOsException('SSH: cannot read the private key (wrong format or passphrase)');
            }
            if ($ssh->login($user, $key)) {
                return true;
            }
        }
        return $password !== '' && $ssh->login($user, $password);
    }

    public function exec(string $command): string
    {
        $out = $this->ssh()->exec($command);
        if (!is_string($out)) {
            throw new RouterOsException('SSH: command was not executed');
        }
        if ($this->ssh()->isTimeout()) {
            throw new RouterOsException('SSH: command timed out');
        }
        return $out;
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
    }

    private function ssh(): SSH2
    {
        if ($this->ssh === null) {
            try {
                $this->ssh = new SSH2($this->host, $this->port, $this->timeout);
                $this->ssh->setTimeout($this->timeout);
            } catch (\Throwable $e) {
                throw new RouterOsException('SSH: cannot connect: ' . $e->getMessage(), 0, $e);
            }
        }
        return $this->ssh;
    }
}
