<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

/** Temporary directory tree for tests that touch the file system. */
final class TempDir
{
    public static function make(string $prefix = 'vpngw-test'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with($dir, sys_get_temp_dir() . '/')) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
