<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

/**
 * Address-list names found on a router: lists that have entries and lists that firewall
 * rules refer to (src-address-list / dst-address-list in filter, nat, mangle, raw).
 * A list the portal fills is normally empty until someone logs in, so names used only in
 * rules matter as much as names with entries.
 *
 * @phpstan-type ListInfo array{entries: int, rules: int}
 */
final class AddressLists
{
    /** Names the portal can use as MT_LIST (same rule as Config / SshClient). */
    public const NAME = '/^[A-Za-z0-9_.-]{1,64}$/';

    /** Firewall tables whose rules may refer to address lists. */
    public const RULE_TABLES = ['filter', 'nat', 'mangle', 'raw'];

    /**
     * @param array<string, int> $entries entries per list name
     * @param iterable<mixed> $ruleRefs values of src-address-list / dst-address-list ("!name" = negated)
     * @return array<string, ListInfo> sorted by name; names the portal cannot use are dropped
     */
    public static function build(array $entries, iterable $ruleRefs): array
    {
        $out = [];
        foreach ($entries as $name => $n) {
            $name = (string) $name;
            if (preg_match(self::NAME, $name) === 1) {
                $out[$name] = ['entries' => $n, 'rules' => 0];
            }
        }
        foreach ($ruleRefs as $ref) {
            $name = is_string($ref) ? ltrim(trim($ref), '!') : '';
            if ($name === '' || preg_match(self::NAME, $name) !== 1) {
                continue;
            }
            $out[$name] ??= ['entries' => 0, 'rules' => 0];
            $out[$name]['rules']++;
        }
        uksort($out, 'strnatcasecmp');
        return $out;
    }

    /**
     * @param iterable<mixed> $rows API / REST rows with a "list" property
     * @return array<string, int>
     */
    public static function countEntries(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $key => $row) {
            if (is_int($key) && is_array($row) && is_string($row['list'] ?? null) && $row['list'] !== '') {
                $out[$row['list']] = ($out[$row['list']] ?? 0) + 1;
            }
        }
        return $out;
    }

    /**
     * @param iterable<mixed> $rows rule rows
     * @return list<string>
     */
    public static function ruleRefs(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $key => $row) {
            if (!is_int($key) || !is_array($row)) {
                continue;
            }
            foreach (['src-address-list', 'dst-address-list'] as $p) {
                if (is_string($row[$p] ?? null) && $row[$p] !== '') {
                    $out[] = $row[$p];
                }
            }
        }
        return $out;
    }
}
