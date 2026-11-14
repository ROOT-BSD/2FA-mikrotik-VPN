<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

use RouterOS\Client;
use RouterOS\Query;

/**
 * RouterOS 6 API-SSL client (binary API protocol over TLS, service api-ssl).
 *
 * The library's default TLS settings are anonymous ciphers (ADH) with no certificate
 * verification — insecure. We always override them: real ciphers, peer verification
 * against the corporate CA.
 */
final class ApiSslClientV6 implements RouterOsClient
{
    private ?Client $client = null;

    public function __construct(
        private readonly string $name,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $pass,
        private readonly string $caFile,
        private readonly int $timeout = 5,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function findEntry(string $list, string $ip): ?string
    {
        return self::firstId($this->run(self::query('/ip/firewall/address-list/print', where: ['list' => $list, 'address' => $ip])));
    }

    public function addEntry(string $list, string $ip, string $timeout, string $comment): void
    {
        $this->run(self::query('/ip/firewall/address-list/add', set: [
            'list' => $list, 'address' => $ip, 'timeout' => $timeout, 'comment' => $comment,
        ]));
    }

    public function updateEntry(string $id, string $timeout, string $comment): void
    {
        $this->run(self::query('/ip/firewall/address-list/set', set: ['.id' => $id, 'timeout' => $timeout, 'comment' => $comment]));
    }

    public function removeEntry(string $id): void
    {
        $this->run(self::query('/ip/firewall/address-list/remove', set: ['.id' => $id]));
    }

    public function dropSessions(string $username, string $ip): void
    {
        foreach (self::ids($this->run(self::query('/ppp/active/print', where: ['name' => $username]))) as $id) {
            $this->run(self::query('/ppp/active/remove', set: ['.id' => $id]));
        }
        foreach (self::ids($this->run(self::query('/ip/ipsec/active-peers/print', where: ['remote-address' => $ip]))) as $id) {
            $this->run(self::query('/ip/ipsec/active-peers/remove', set: ['.id' => $id]));
        }
    }

    /** @return array<int|string, mixed> parsed response rows */
    private function run(Query $query): array
    {
        try {
            $client = $this->client();
            $raw = $client->query($query)->read(false);
        } catch (RouterOsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->client = null;
            throw new RouterOsException("{$this->name}: API error: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($raw)) {
            throw new RouterOsException("{$this->name}: unexpected API response");
        }
        if (in_array('!trap', $raw, true) || in_array('!fatal', $raw, true)) {
            $message = 'unknown error';
            foreach ($raw as $word) {
                if (is_string($word) && str_starts_with($word, '=message=')) {
                    $message = substr($word, 9);
                }
            }
            throw new RouterOsException("{$this->name}: {$query->getEndpoint()}: {$message}");
        }
        return $client->parseResponse($raw);
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'host' => $this->host,
                'port' => $this->port,
                'user' => $this->user,
                'pass' => $this->pass,
                'ssl' => true,
                'ssl_options' => [
                    'ciphers' => 'HIGH:!aNULL:!eNULL:!MD5:!RC4',
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'cafile' => $this->caFile,
                ],
                'timeout' => $this->timeout,
                'socket_timeout' => $this->timeout,
                'attempts' => 1,
                'delay' => 0,
                'legacy' => false,
            ]);
        }
        return $this->client;
    }

    /**
     * Builds an API sentence. Every value is its own length-prefixed API word
     * ("=key=value" / "?key=value"), so values cannot inject extra commands.
     *
     * @param array<string, string> $where
     * @param array<string, string> $set
     */
    public static function query(string $endpoint, array $where = [], array $set = []): Query
    {
        $words = [];
        foreach ($where as $k => $v) {
            $words[] = '?' . $k . '=' . self::clean($v);
        }
        foreach ($set as $k => $v) {
            $words[] = '=' . $k . '=' . self::clean($v);
        }
        return new Query($endpoint, $words);
    }

    private static function clean(string $v): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $v);
    }

    /** @param array<int|string, mixed> $rows */
    private static function firstId(array $rows): ?string
    {
        return self::ids($rows)[0] ?? null;
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $key => $row) {
            if (is_int($key) && is_array($row) && is_string($row['.id'] ?? null)) {
                $ids[] = $row['.id'];
            }
        }
        return $ids;
    }
}
