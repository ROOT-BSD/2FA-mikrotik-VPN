<?php

declare(strict_types=1);

namespace VpnGw\Agent;

/**
 * Checks an uploaded release archive before anything is extracted:
 *  - one top-level directory "vpn-gw-X.Y[.Z]/" that matches the version;
 *  - no absolute paths, "..", backslashes or symbolic links (zip-slip);
 *  - size limits against zip bombs;
 *  - after extraction: VERSION, install.sh and composer.json are present.
 * Uses the "unzip" utility (Info-ZIP) which the installer requires anyway.
 */
final class ReleaseInspector
{
    public const MAX_FILES = 5000;
    public const MAX_BYTES = 200 * 1024 * 1024;

    /** Version encoded in the file name vpn-gw-X.Y[.Z].zip, or null. */
    public static function versionFromName(string $name): ?string
    {
        return preg_match('/^vpn-gw-(\d{1,3}\.\d{1,3}(?:\.\d{1,3})?)\.zip$/', $name, $m) === 1 ? $m[1] : null;
    }

    /**
     * Parses a "sha256sum" line (or a bare hash).
     *
     * @return array{0: string, 1: string|null}|null [hash, file name]
     */
    public static function parseChecksum(string $text): ?array
    {
        if (preg_match('/^\s*([a-fA-F0-9]{64})(?:\s+\*?(\S+))?\s*$/', trim(strtok($text, "\n") ?: ''), $m) !== 1) {
            return null;
        }
        return [strtolower($m[1]), ($m[2] ?? '') === '' ? null : $m[2]];
    }

    /** @return string the version found inside the archive */
    public function inspect(string $zip): string
    {
        $names = array_values(array_filter(explode("\n", Proc::mustRun(['unzip', '-Z1', $zip])), static fn ($l) => $l !== ''));
        if ($names === [] || count($names) > self::MAX_FILES) {
            throw new \RuntimeException('Archive is empty or has too many files');
        }
        $top = null;
        foreach ($names as $n) {
            if (str_starts_with($n, '/') || str_contains($n, '\\') || preg_match('#(^|/)\.\.(/|$)#', $n) || preg_match('/[\x00-\x1F]/', $n)) {
                throw new \RuntimeException("Unsafe path in archive: $n");
            }
            $first = explode('/', $n, 2)[0];
            $top ??= $first;
            if ($first !== $top) {
                throw new \RuntimeException('Archive must contain a single top-level directory');
            }
        }
        $version = preg_match('/^vpn-gw-(\d{1,3}\.\d{1,3}(?:\.\d{1,3})?)$/', (string) $top, $m) === 1 ? $m[1] : null;
        if ($version === null) {
            throw new \RuntimeException("Top-level directory must be vpn-gw-X.Y, found: $top");
        }
        // Long listing: the first column is the Unix mode; "l" marks a symbolic link.
        foreach (explode("\n", Proc::mustRun(['unzip', '-Z', '-l', $zip])) as $line) {
            if (preg_match('/^l[rwx-]{9}\s/', $line)) {
                throw new \RuntimeException('Archive contains a symbolic link');
            }
        }
        $total = Proc::mustRun(['unzip', '-Zt', $zip]);
        if (preg_match('/(\d+) bytes uncompressed/', $total, $m) === 1 && (int) $m[1] > self::MAX_BYTES) {
            throw new \RuntimeException('Archive is too large when unpacked');
        }
        return $version;
    }

    /** Extracts into $dir (must be empty) and returns the release root. */
    public function extract(string $zip, string $dir, string $version): string
    {
        Proc::mustRun(['unzip', '-q', '-n', $zip, '-d', $dir]);
        $root = $dir . '/vpn-gw-' . $version;
        foreach (['VERSION', 'install.sh', 'composer.json', 'src/Version.php'] as $f) {
            if (!is_file("$root/$f") || is_link("$root/$f")) {
                throw new \RuntimeException("Release is missing $f");
            }
        }
        $inside = trim((string) file_get_contents("$root/VERSION"));
        if ($inside !== $version) {
            throw new \RuntimeException("VERSION file says $inside, directory says $version");
        }
        return $root;
    }
}
