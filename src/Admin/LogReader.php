<?php

declare(strict_types=1);

namespace VpnGw\Admin;

/**
 * Reads the tail of the JSON audit log for the admin panel viewer.
 *
 * @phpstan-type Entry array{time: string, level: string, event: string, category: string, user: string, ip: string, details: string}
 */
final class LogReader
{
    private const CONTEXT_SHOWN = ['event', 'category', 'user', 'ip', 'app_version', 'remote_addr', 'xff_raw', 'ua'];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Newest first, at most $limit entries matching all non-empty filters.
     *
     * @param array{q?: string, level?: string, category?: string} $filter
     * @return list<Entry>
     */
    public function tail(int $limit = 200, array $filter = [], int $maxBytes = 2_097_152): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return [];
        }
        $fh = fopen($this->path, 'rb');
        if ($fh === false) {
            return [];
        }
        $size = (int) filesize($this->path);
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh); // skip the partial first line
        }
        $lines = [];
        while (($line = fgets($fh)) !== false) {
            $lines[] = $line;
        }
        fclose($fh);

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
