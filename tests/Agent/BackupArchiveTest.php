<?php

declare(strict_types=1);

namespace VpnGw\Tests\Agent;

use PHPUnit\Framework\TestCase;
use VpnGw\Agent\BackupArchive;
use VpnGw\Tests\Support\TempDir;

final class BackupArchiveTest extends TestCase
{
    private string $dir;
    private BackupArchive $archive;

    protected function setUp(): void
    {
        $this->dir = TempDir::make();
        // minimal Argon2id cost keeps the test fast; production uses MODERATE
        $this->archive = new BackupArchive(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, 8 * 1024 * 1024);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** @return array<string, array{int}> */
    public static function sizes(): array
    {
        return ['empty' => [0], 'small' => [1000], 'exactly one chunk' => [65536], 'several chunks' => [200_001]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function testRoundTrip(int $size): void
    {
        $plain = random_bytes(max(1, $size));
        $plain = substr($plain, 0, $size);
        file_put_contents("$this->dir/in", $plain);
        $this->archive->encrypt("$this->dir/in", "$this->dir/enc", 'correct horse battery');
        $enc = (string) file_get_contents("$this->dir/enc");
        self::assertStringStartsWith('VGBK', $enc);
        if ($size > 32) {
            self::assertStringNotContainsString(substr($plain, 0, 32), $enc);
        }
        $this->archive->decrypt("$this->dir/enc", "$this->dir/out", 'correct horse battery');
        self::assertSame($plain, file_get_contents("$this->dir/out"));
    }

    public function testWrongPassphraseFailsAndLeavesNoOutput(): void
    {
        file_put_contents("$this->dir/in", 'secret data');
        $this->archive->encrypt("$this->dir/in", "$this->dir/enc", 'right passphrase');
        try {
            $this->archive->decrypt("$this->dir/enc", "$this->dir/out", 'wrong passphrase');
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Wrong passphrase', $e->getMessage());
        }
        self::assertFileDoesNotExist("$this->dir/out");
    }

    public function testTruncationIsDetected(): void
    {
        file_put_contents("$this->dir/in", random_bytes(150_000));
        $this->archive->encrypt("$this->dir/in", "$this->dir/enc", 'passphrase-123');
        $enc = (string) file_get_contents("$this->dir/enc");
        // cut after the first complete chunk: every remaining chunk boundary is still valid
        $firstLen = (int) unpack('N', substr($enc, 53, 4))[1];
        file_put_contents("$this->dir/cut", substr($enc, 0, 53 + 4 + $firstLen));
        $this->expectException(\RuntimeException::class);
        $this->archive->decrypt("$this->dir/cut", "$this->dir/out", 'passphrase-123');
    }

    public function testTamperingIsDetected(): void
    {
        file_put_contents("$this->dir/in", random_bytes(5000));
        $this->archive->encrypt("$this->dir/in", "$this->dir/enc", 'passphrase-123');
        $enc = (string) file_get_contents("$this->dir/enc");
        $enc[100] = chr(ord($enc[100]) ^ 1);
        file_put_contents("$this->dir/enc", $enc);
        $this->expectException(\RuntimeException::class);
        $this->archive->decrypt("$this->dir/enc", "$this->dir/out", 'passphrase-123');
    }

    public function testRejectsForeignFile(): void
    {
        file_put_contents("$this->dir/enc", 'PK' . random_bytes(100));
        $this->expectException(\RuntimeException::class);
        $this->archive->decrypt("$this->dir/enc", "$this->dir/out", 'x');
    }
}
