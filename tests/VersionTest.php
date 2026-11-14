<?php

declare(strict_types=1);

namespace VpnGw\Tests;

use PHPUnit\Framework\TestCase;
use VpnGw\Version;

final class VersionTest extends TestCase
{
    public function testConstantMatchesVersionFileAndComposer(): void
    {
        $root = dirname(__DIR__);
        self::assertSame(trim((string) file_get_contents($root . '/VERSION')), Version::CURRENT);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        self::assertSame(Version::CURRENT, is_array($composer) ? ($composer['version'] ?? null) : null);
        self::assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', Version::CURRENT);
    }
}
