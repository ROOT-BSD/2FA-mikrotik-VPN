<?php

declare(strict_types=1);

namespace VpnGw\Agent;

/**
 * Passphrase encryption of backup files.
 *
 * Format: "VGBK" | version (1 byte) | opslimit (uint32 BE) | memlimit (uint32 BE) | salt (16)
 *         | secretstream header (24) | chunks: length (uint32 BE) + ciphertext.
 * Key: Argon2id (sodium_crypto_pwhash). Data: XChaCha20-Poly1305 secretstream, 64 KiB
 * chunks; the last chunk carries TAG_FINAL, so truncation is detected.
 */
final class BackupArchive
{
    private const MAGIC = 'VGBK';
    private const VERSION = 1;
    private const CHUNK = 65536;

    public function __construct(
        private readonly int $opsLimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        private readonly int $memLimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
    ) {
    }

    public function encrypt(string $in, string $out, #[\SensitiveParameter] string $passphrase): void
    {
        if ($passphrase === '') {
            throw new \InvalidArgumentException('Empty backup passphrase');
        }
        $src = self::open($in, 'rb');
        $dst = self::open($out, 'xb');
        try {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = $this->key($passphrase, $salt, $this->opsLimit, $this->memLimit);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            sodium_memzero($key);
            fwrite($dst, self::MAGIC . chr(self::VERSION) . pack('NN', $this->opsLimit, $this->memLimit) . $salt . $header);
            $chunk = (string) fread($src, self::CHUNK);
            do {
                $next = feof($src) ? '' : (string) fread($src, self::CHUNK);
                $final = $next === '' && feof($src);
                $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ct = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($dst, pack('N', strlen($ct)) . $ct);
                $chunk = $next;
            } while (!$final);
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    public function decrypt(string $in, string $out, #[\SensitiveParameter] string $passphrase): void
    {
        $src = self::open($in, 'rb');
        $dst = self::open($out, 'xb');
        $ok = false;
        try {
            $head = (string) fread($src, 4 + 1 + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($head) < 13 || !str_starts_with($head, self::MAGIC) || ord($head[4]) !== self::VERSION) {
                throw new \RuntimeException('Not a VPN-GW backup file');
            }
            $limits = unpack('Nops/Nmem', substr($head, 5, 8));
            if (!is_array($limits)) {
                throw new \RuntimeException('Corrupted backup header');
            }
            $ops = (int) $limits['ops'];
            $mem = (int) $limits['mem'];
            if ($ops < 1 || $ops > 16 || $mem < 8192 || $mem > 2 * 1024 * 1024 * 1024) {
                throw new \RuntimeException('Corrupted backup header');
            }
            $salt = substr($head, 13, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $header = substr($head, 13 + SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = $this->key($passphrase, $salt, $ops, $mem);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);
            $final = false;
            while (!$final) {
                $lenRaw = (string) fread($src, 4);
                if (strlen($lenRaw) !== 4) {
                    throw new \RuntimeException('Backup file is truncated');
                }
                $len = (int) (unpack('N', $lenRaw)[1] ?? 0);
                if ($len < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $len > self::CHUNK + 64) {
                    throw new \RuntimeException('Corrupted backup chunk');
                }
                $ct = (string) fread($src, $len);
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ct);
                if ($res === false) {
                    throw new \RuntimeException('Wrong passphrase or corrupted backup');
                }
                [$plain, $tag] = $res;
                fwrite($dst, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (fread($src, 1) !== '') {
                throw new \RuntimeException('Unexpected data after the end of the backup');
            }
            $ok = true;
        } finally {
            fclose($src);
            fclose($dst);
            if (!$ok) {
                @unlink($out);
            }
        }
    }

    private function key(#[\SensitiveParameter] string $passphrase, string $salt, int $ops, int $mem): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $fh = @fopen($path, $mode);
        if ($fh === false) {
            throw new \RuntimeException("Cannot open $path");
        }
        return $fh;
    }
}
