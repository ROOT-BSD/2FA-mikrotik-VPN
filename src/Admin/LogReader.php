<?php

declare(strict_types=1);

namespace VpnGw\Admin;

/**
 * Reads the JSON audit log for the admin panel viewer: the current file (LOG_PATH) or one
 * of its rotated copies next to it (logrotate: audit.log.1, audit.log.2.gz, … or with
 * dateext audit.log-20261123[.gz]).
 *
 * @phpstan-type LogFile array{name: string, size: int, mtime: int, gz: bool, current: bool}
 * @phpstan-type Entry array{time: string, level: string, event: string, category: string, user: string, ip: string, details: string}
 */
final class LogReader
{
    private const CONTEXT_SHOWN = ['event', 'category', 'user', 'ip', 'app_version', 'remote_addr', 'xff_raw', 'ua'];

    /** Decompressed bytes read from one .gz at most (guards against a gzip bomb). */
    private const GZ_MAX_BYTES = 67_108_864;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * The current log and its rotated copies, the current first, then the newest first.
     * Only files named after LOG_PATH are listed — the name is the whitelist for show/download.
     *
     * @return list<LogFile>
     */
    public function files(): array
    {
        $dir = dirname($this->path);
        $base = basename($this->path);
        $out = [];
        $names = is_dir($dir) && is_readable($dir) ? (scandir($dir) ?: []) : [];
        $re = '/^' . preg_quote($base, '/') . '(?:\.\d{1,4}|-\d{8}(?:\d{2})?)?(?:\.gz)?$/D';
        foreach ($names as $n) {
            $full = $dir . '/' . $n;
            if (preg_match($re, $n) !== 1 || !is_file($full) || is_link($full)) {
                continue;
            }
            $out[] = ['name' => $n, 'size' => (int) filesize($full), 'mtime' => (int) filemtime($full),
                'gz' => str_ends_with($n, '.gz'), 'current' => $n === $base];
        }
        usort($out, static fn (array $a, array $b): int => [$b['current'], $b['mtime'], $a['name']] <=> [$a['current'], $a['mtime'], $b['name']]);
        return $out;
    }

    /** Full path of a listed log file, or null if $name is not one of files(). '' — the current log. */
    public function pathOf(string $name): ?string
    {
        if ($name === '') {
            return $this->path;
        }
        foreach ($this->files() as $f) {
            if ($f['name'] === $name) {
                return dirname($this->path) . '/' . $f['name'];
            }
        }
        return null;
    }

    /**
     * Newest first, at most $limit entries matching all non-empty filters.
     *
     * @param array{q?: string, level?: string, category?: string} $filter
     * @param string $file a name from files(); '' — the current log
     * @return list<Entry>
     */
    public function tail(int $limit = 200, array $filter = [], int $maxBytes = 8_388_608, string $file = ''): array
    {
        $path = $this->pathOf($file);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return [];
        }
        $lines = str_ends_with($path, '.gz') ? self::gzLines($path, $maxBytes) : self::plainLines($path, $maxBytes);

        $q = mb_strtolower(trim($filter['q'] ?? ''));
        $level = $filter['level'] ?? '';
        $category = $filter['category'] ?? '';
        $out = [];
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $entry = self::parse($lines[$i]);
            if ($entry === null
                || ($level !== '' && $entry['level'] !== $level)
                || ($category !== '' && $entry['category'] !== $category)
                || ($q !== '' && !str_contains(mb_strtolower($lines[$i]), $q))) {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * «Показати всі»: entries from all log files — the current one, then the rotated copies
     * from the newest — newest first, at most $limit in total. Each entry names its file.
     * Older files are not opened once $limit is reached.
     *
     * @param array{q?: string, level?: string, category?: string} $filter
     * @return list<array{time: string, level: string, event: string, category: string, user: string, ip: string, details: string, file: string}>
     */
    public function tailAll(int $limit = 1000, array $filter = [], int $maxBytes = 8_388_608): array
    {
        $out = [];
        foreach ($this->files() as $f) {
            if (count($out) >= $limit) {
                break;
            }
            foreach ($this->tail($limit - count($out), $filter, $maxBytes, $f['current'] ? '' : $f['name']) as $entry) {
                $out[] = $entry + ['file' => $f['name']];
            }
        }
        return $out;
    }

    /**
     * The last $maxBytes of a plain file, split into lines (a cut first line is dropped).
     *
     * @return list<string>
     */
    private static function plainLines(string $path, int $maxBytes): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        if ((int) filesize($path) > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh); // skip the partial first line
        }
        $lines = [];
        while (($line = fgets($fh)) !== false) {
            $lines[] = $line;
        }
        fclose($fh);
        return $lines;
    }

    /**
     * The last lines of a gzip-compressed log, about $maxBytes of them; reading stops after
     * GZ_MAX_BYTES decompressed bytes.
     *
     * @return list<string>
     */
    private static function gzLines(string $path, int $maxBytes): array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return [];
        }
        $lines = [];
        $kept = 0;
        $read = 0;
        $first = 0;
        while ($read < self::GZ_MAX_BYTES && ($line = gzgets($gz, 1_048_576)) !== false) {
            $len = strlen($line);
            $read += $len;
            $lines[] = $line;
            $kept += $len;
            while ($kept > $maxBytes && $kept > $len) {
                $kept -= strlen($lines[$first]);
                unset($lines[$first]);
                $first++;
            }
        }
        gzclose($gz);
        return array_values($lines);
    }

    /** @return Entry|null */
    public static function parse(string $line): ?array
    {
        $d = json_decode($line, true);
        if (!is_array($d) || !is_array($d['context'] ?? null)) {
            return null;
        }
        $ctx = $d['context'];
        $details = [];
        foreach ($ctx as $k => $v) {
            if (!in_array($k, self::CONTEXT_SHOWN, true) && is_scalar($v)) {
                $details[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        $time = is_string($d['datetime'] ?? null) ? $d['datetime'] : '';
        $ts = strtotime($time);
        return [
            'time' => $ts === false ? $time : date('Y-m-d H:i:s', $ts),
            'level' => strtolower(is_string($d['level_name'] ?? null) ? $d['level_name'] : ''),
            'event' => is_string($ctx['event'] ?? null) ? $ctx['event'] : (is_string($d['message'] ?? null) ? $d['message'] : ''),
            'category' => is_string($ctx['category'] ?? null) ? $ctx['category'] : '',
            'user' => is_string($ctx['user'] ?? null) ? $ctx['user'] : '',
            'ip' => is_string($ctx['ip'] ?? null) ? $ctx['ip'] : (is_string($ctx['remote_addr'] ?? null) ? $ctx['remote_addr'] : ''),
            'details' => implode(' ', $details),
        ];
    }
}
