<?php

declare(strict_types=1);

namespace VpnGw\Security;

/** Authenticated encryption (sodium secretbox) and HMAC helpers. */
final class Crypto
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $encKey,
        #[\SensitiveParameter] private readonly string $macKey,
    ) {
        if (strlen($encKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || strlen($macKey) < 32) {
            throw new \InvalidArgumentException('Crypto keys must be 32 bytes');
        }
    }

    /** @return string nonce || ciphertext */
    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->encKey);
    }

    public function decrypt(string $blob): string
    {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) < $n + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Ciphertext too short');
        }
        $plain = sodium_crypto_secretbox_open(substr($blob, $n), substr($blob, 0, $n), $this->encKey);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (wrong APP_KEY or corrupted data)');
        }
        return $plain;
    }

    public function hmac(#[\SensitiveParameter] string $data): string
    {
        return hash_hmac('sha256', $data, $this->macKey);
    }

    /** @param int<1, max> $bytes */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
