<?php

declare(strict_types=1);

namespace VpnGw\Tests\Agent;

use PHPUnit\Framework\TestCase;
use VpnGw\Agent\ReleaseInspector;
use VpnGw\Tests\Support\TempDir;

/** Uses the real Info-ZIP tools (zip/unzip), like the agent on the server. */
final class ReleaseInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::make();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** @param array<string, string> $files relative path => content */
    private function zip(array $files, string $name = 'r.zip', bool $symlinks = false): string
    {
        $src = "$this->dir/src-" . bin2hex(random_bytes(3));
        mkdir($src);
        foreach ($files as $path => $content) {
            @mkdir(dirname("$src/$path"), 0700, true);
            if (str_starts_with($content, '@link:')) {
                symlink(substr($content, 6), "$src/$path");
            } else {
                file_put_contents("$src/$path", $content);
            }
        }
        exec('cd ' . escapeshellarg($src) . ' && zip -qr' . ($symlinks ? 'y' : '') . ' ' . escapeshellarg("$this->dir/$name") . ' .');
        return "$this->dir/$name";
    }

    /** @return array<string, string> */
    private static function release(string $v): array
    {
        return [
            "vpn-gw-$v/VERSION" => "$v\n",
            "vpn-gw-$v/install.sh" => "#!/bin/bash\n",
            "vpn-gw-$v/composer.json" => '{}',
            "vpn-gw-$v/src/Version.php" => '<?php',
        ];
    }

    public function testValidRelease(): void
    {
        $zip = $this->zip(self::release('0.3'));
        $i = new ReleaseInspector();
        self::assertSame('0.3', $i->inspect($zip));
        mkdir("$this->dir/out");
        self::assertSame("$this->dir/out/vpn-gw-0.3", $i->extract($zip, "$this->dir/out", '0.3'));
    }

    public function testRejectsTwoTopLevelDirectories(): void
    {
        $zip = $this->zip(self::release('0.3') + ['other/x' => 'y']);
        $this->expectException(\RuntimeException::class);
        (new ReleaseInspector())->inspect($zip);
    }

    public function testRejectsWrongTopLevelName(): void
    {
        $zip = $this->zip(['release/VERSION' => '0.3']);
        $this->expectExceptionMessage('vpn-gw-X.Y');
        (new ReleaseInspector())->inspect($zip);
    }

    public function testRejectsSymlinks(): void
    {
        $zip = $this->zip(self::release('0.3') + ['vpn-gw-0.3/evil' => '@link:/etc/shadow'], 'l.zip', true);
        $this->expectExceptionMessage('symbolic link');
        (new ReleaseInspector())->inspect($zip);
    }

    public function testRejectsPathTraversal(): void
    {
        // zip(1) refuses to store "..", so craft the archive with PHP's zip writer when available
        if (!class_exists(\ZipArchive::class)) {
            $zip = "$this->dir/t.zip";
            file_put_contents($zip, self::rawZip('vpn-gw-0.3/../../etc/cron.d/x', 'boom'));
        } else {
            $zip = "$this->dir/t.zip";
            $z = new \ZipArchive();
            $z->open($zip, \ZipArchive::CREATE);
            $z->addFromString('vpn-gw-0.3/../../etc/cron.d/x', 'boom');
            $z->close();
        }
        $this->expectExceptionMessage('Unsafe path');
        (new ReleaseInspector())->inspect($zip);
    }

    public function testExtractChecksVersionFile(): void
    {
        $files = self::release('0.3');
        $files['vpn-gw-0.3/VERSION'] = "0.4\n";
        $zip = $this->zip($files);
        $i = new ReleaseInspector();
        $i->inspect($zip);
        mkdir("$this->dir/out");
        $this->expectExceptionMessage('VERSION file says 0.4');
        $i->extract($zip, "$this->dir/out", '0.3');
    }

    public function testNamesAndChecksums(): void
    {
        self::assertSame('0.2', ReleaseInspector::versionFromName('vpn-gw-0.2.zip'));
        self::assertSame('1.10.3', ReleaseInspector::versionFromName('vpn-gw-1.10.3.zip'));
        self::assertNull(ReleaseInspector::versionFromName('vpn-gw-0.2.zip.exe'));
        self::assertNull(ReleaseInspector::versionFromName('../vpn-gw-0.2.zip'));
        $h = str_repeat('ab', 32);
        self::assertSame([$h, 'vpn-gw-0.2.zip'], ReleaseInspector::parseChecksum("$h  vpn-gw-0.2.zip\n"));
        self::assertSame([$h, 'vpn-gw-0.2.zip'], ReleaseInspector::parseChecksum(strtoupper($h) . " *vpn-gw-0.2.zip"));
        self::assertSame([$h, null], ReleaseInspector::parseChecksum("  $h  "));
        self::assertNull(ReleaseInspector::parseChecksum('abc'));
        self::assertNull(ReleaseInspector::parseChecksum("$h vpn-gw-0.2.zip extra"));
    }

    /** Single-entry stored ZIP with an arbitrary file name (for the traversal test). */
    private static function rawZip(string $name, string $data): string
    {
        $crc = crc32($data);
        $len = strlen($data);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0) . $name . $data;
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, 0) . $name;
        return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    }
}
