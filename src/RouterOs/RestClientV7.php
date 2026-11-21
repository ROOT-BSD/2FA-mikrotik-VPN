<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

/**
 * RouterOS 7 REST API client (HTTPS + JSON, service www-ssl).
 * The router certificate is verified against the corporate CA.
 */
final class RestClientV7 implements RouterOsClient
{
    /** @var non-empty-string */
    private readonly string $caFile;

    public function __construct(
        private readonly string $name,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $pass,
        string $caFile,
        private readonly int $timeout = 5,
    ) {
        if ($caFile === '') {
            throw new \InvalidArgumentException('MT_CA_FILE is required: the router certificate must be verified');
        }
        $this->caFile = $caFile;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function findEntry(string $list, string $ip): ?string
    {
        $rows = $this->request('GET', '/ip/firewall/address-list', ['list' => $list, 'address' => $ip]);
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['.id'] ?? null)) {
                return $row['.id'];
            }
        }
        return null;
    }

    public function addEntry(string $list, string $ip, string $timeout, string $comment): void
    {
        $this->request('PUT', '/ip/firewall/address-list', [], [
            'list' => $list, 'address' => $ip, 'timeout' => $timeout, 'comment' => $comment,
        ]);
    }

    public function updateEntry(string $id, string $timeout, string $comment): void
    {
        $this->request('PATCH', '/ip/firewall/address-list/' . rawurlencode($id), [], [
            'timeout' => $timeout, 'comment' => $comment,
        ]);
    }

    public function removeEntry(string $id): void
    {
        $this->request('DELETE', '/ip/firewall/address-list/' . rawurlencode($id));
    }

    public function dropSessions(string $username, string $ip): void
    {
        foreach ($this->request('GET', '/ppp/active', ['name' => $username]) as $row) {
            if (is_array($row) && is_string($row['.id'] ?? null)) {
                $this->request('DELETE', '/ppp/active/' . rawurlencode($row['.id']));
            }
        }
        foreach ($this->request('GET', '/ip/ipsec/active-peers', ['remote-address' => $ip]) as $row) {
            if (is_array($row) && is_string($row['.id'] ?? null)) {
                $this->request('DELETE', '/ip/ipsec/active-peers/' . rawurlencode($row['.id']));
            }
        }
    }

    public function addressLists(): array
    {
        $entries = AddressLists::countEntries($this->request('GET', '/ip/firewall/address-list', ['.proplist' => 'list']));
        $refs = [];
        foreach (AddressLists::RULE_TABLES as $table) {
            try {
                $rows = $this->request('GET', '/ip/firewall/' . $table, ['.proplist' => 'src-address-list,dst-address-list']);
            } catch (RouterOsException $e) {
                if ($table === 'raw') {
                    continue;
                }
                throw $e;
            }
            array_push($refs, ...AddressLists::ruleRefs($rows));
        }
        return AddressLists::build($entries, $refs);
    }

    /**
     * @param 'GET'|'PUT'|'PATCH'|'DELETE' $method
     * @param array<string, string> $query
     * @param array<string, string>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = sprintf('https://%s:%d/rest%s', $this->host, $this->port, $path);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RouterOsException("{$this->name}: curl_init failed");
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->user . ':' . $this->pass,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => $this->caFile,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            throw new RouterOsException("{$this->name}: {$method} {$path} failed: {$err}");
        }
        if ($status >= 400) {
            $detail = json_decode($raw, true);
            $msg = is_array($detail) ? (string) ($detail['detail'] ?? $detail['message'] ?? '') : '';
            throw new RouterOsException("{$this->name}: {$method} {$path} -> HTTP {$status} {$msg}");
        }
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RouterOsException("{$this->name}: invalid JSON from router");
        }
        return is_array($data) ? $data : [];
    }
}
