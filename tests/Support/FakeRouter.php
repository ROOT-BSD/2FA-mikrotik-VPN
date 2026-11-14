<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

use VpnGw\RouterOs\RouterOsClient;
use VpnGw\RouterOs\RouterOsException;

/** In-memory RouterOS address list for tests. */
final class FakeRouter implements RouterOsClient
{
    /** @var array<string, array{list: string, address: string, timeout: string, comment: string}> */
    public array $entries = [];
    /** @var list<string> */
    public array $calls = [];
    /** @var list<array{string, string}> */
    public array $droppedSessions = [];
    public bool $fail = false;
    private int $seq = 0;

    public function __construct(private readonly string $name = 'fake')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function findEntry(string $list, string $ip): ?string
    {
        $this->guard('find');
        foreach ($this->entries as $id => $e) {
            if ($e['list'] === $list && $e['address'] === $ip) {
                return $id;
            }
        }
        return null;
    }

    public function addEntry(string $list, string $ip, string $timeout, string $comment): void
    {
        $this->guard('add');
        $this->entries['*' . dechex(++$this->seq)] = compact('list', 'timeout', 'comment') + ['address' => $ip];
    }

    public function updateEntry(string $id, string $timeout, string $comment): void
    {
        $this->guard('update');
        $this->entries[$id]['timeout'] = $timeout;
        $this->entries[$id]['comment'] = $comment;
    }

    public function removeEntry(string $id): void
    {
        $this->guard('remove');
        unset($this->entries[$id]);
    }

    public function dropSessions(string $username, string $ip): void
    {
        $this->guard('drop');
        $this->droppedSessions[] = [$username, $ip];
    }

    private function guard(string $op): void
    {
        $this->calls[] = $op;
        if ($this->fail) {
            throw new RouterOsException("{$this->name}: simulated failure");
        }
    }
}
