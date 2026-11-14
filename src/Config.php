<?php

declare(strict_types=1);

namespace VpnGw;

use Dotenv\Dotenv;
use VpnGw\Security\Cidr;

/**
 * Validated application configuration. Fails closed: any missing or malformed
 * value throws ConfigException at startup instead of degrading security later.
 *
 * @phpstan-type RouterDef array{name: string, host: string, version: int, port: int}
 */
final class Config
{
    private const REQUIRED = [
        'APP_BASE_PATH', 'APP_KEY', 'APP_HMAC_KEY', 'TRUSTED_PROXIES', 'INTERNAL_NETWORKS',
        'LDAP_HOSTS', 'LDAP_UPN_SUFFIX', 'LDAP_BASE_DN', 'LDAP_VPN_GROUP_DN', 'LDAP_CA_FILE',
        'ROUTERS', 'MT_USER', 'MT_PASS', 'MT_CA_FILE', 'MT_LIST', 'MT_TIMEOUT',
        'DB_PATH', 'LOCK_DIR', 'LOG_PATH',
    ];

    private const DEFAULTS = [
        'APP_ENV' => 'production',
        'EMAIL_OTP_ENABLED' => 'false',
        'SMTP_HOST' => '',
        'SMTP_PORT' => '587',
        'SMTP_USER' => '',
        'SMTP_PASS' => '',
        'SMTP_FROM' => '',
        'SMTP_CA_FILE' => '',
        'IT_CONTACT' => 'ІТ-служба',
        'RATE_LOGIN_IP' => '5/900',
        'RATE_LOGIN_USER' => '3/900',
        'RATE_MFA_ATTEMPTS' => '3',
        'RATE_EMAIL_OTP' => '3/3600',
        'LOCKOUT_SECONDS' => '900',
    ];

    /** @var array<string, string> */
    private array $v;

    /** @var list<RouterDef> */
    private array $routers = [];

    /** @param array<string, string> $values */
    private function __construct(array $values)
    {
        $this->v = $values + self::DEFAULTS;
    }

    public static function load(string $dir): self
    {
        $dotenv = Dotenv::createImmutable($dir);
        $dotenv->load();

        $values = [];
        foreach ($_ENV as $k => $val) {
            if (is_string($k) && is_scalar($val)) {
                $values[$k] = (string) $val;
            }
        }
        return self::fromArray($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        $cfg = new self($values);
        $cfg->validate();
        return $cfg;
    }

    private function validate(): void
    {
        foreach (self::REQUIRED as $key) {
            if (trim($this->v[$key] ?? '') === '') {
                throw new ConfigException("Missing required setting $key");
            }
        }
        if (!preg_match('#^/[A-Za-z0-9_-]{1,64}$#', $this->v['APP_BASE_PATH'])) {
            throw new ConfigException('APP_BASE_PATH must look like /abc123');
        }
        foreach (['APP_KEY', 'APP_HMAC_KEY'] as $k) {
            if (strlen($this->key($k)) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new ConfigException("$k must be base64:<32 random bytes>");
            }
        }
        foreach ($this->list('TRUSTED_PROXIES') as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new ConfigException("TRUSTED_PROXIES: invalid IP $ip");
            }
        }
        foreach ($this->list('INTERNAL_NETWORKS') as $cidr) {
            if (!Cidr::isValid($cidr)) {
                throw new ConfigException("INTERNAL_NETWORKS: invalid CIDR $cidr");
            }
        }
        foreach ($this->list('LDAP_HOSTS') as $host) {
            if (!str_starts_with($host, 'ldaps://')) {
                throw new ConfigException("LDAP_HOSTS: $host must be an ldaps:// URI");
            }
        }
        if (!preg_match('/^\d{1,3}:[0-5]\d:[0-5]\d$/', $this->v['MT_TIMEOUT'])) {
            throw new ConfigException('MT_TIMEOUT must be HH:MM:SS');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $this->v['MT_LIST'])) {
            throw new ConfigException('MT_LIST contains invalid characters');
        }
        foreach (['RATE_LOGIN_IP', 'RATE_LOGIN_USER', 'RATE_EMAIL_OTP'] as $k) {
            $this->rate($k);
        }
        $this->routers = $this->parseRouters($this->v['ROUTERS']);
    }

    /** @return list<RouterDef> */
    private function parseRouters(string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException('ROUTERS must be valid JSON: ' . $e->getMessage());
        }
        if (!is_array($data) || $data === [] || !array_is_list($data)) {
            throw new ConfigException('ROUTERS must be a non-empty JSON array');
        }
        $out = [];
        foreach ($data as $i => $r) {
            if (!is_array($r)
                || !is_string($r['name'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $r['name'])
                || !is_string($r['host'] ?? null) || $r['host'] === ''
                || !in_array($r['version'] ?? null, [6, 7], true)
                || !is_int($r['port'] ?? null) || $r['port'] < 1 || $r['port'] > 65535) {
                throw new ConfigException("ROUTERS[$i]: need name, host, version (6|7), port");
            }
            $out[] = ['name' => $r['name'], 'host' => $r['host'], 'version' => $r['version'], 'port' => $r['port']];
        }
        return $out;
    }

    public function get(string $key): string
    {
        if (!array_key_exists($key, $this->v)) {
            throw new ConfigException("Unknown setting $key");
        }
        return $this->v[$key];
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key))), static fn ($s) => $s !== ''));
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOL);
    }

    public function int(string $key): int
    {
        $raw = $this->get($key);
        if (!ctype_digit($raw)) {
            throw new ConfigException("$key must be a non-negative integer");
        }
        return (int) $raw;
    }

    /** Raw bytes of a "base64:..." key. */
    public function key(string $key): string
    {
        $raw = $this->get($key);
        if (!str_starts_with($raw, 'base64:')) {
            return '';
        }
        $bytes = base64_decode(substr($raw, 7), true);
        return $bytes === false ? '' : $bytes;
    }

    /** @return array{0: int, 1: int} [limit, windowSeconds] */
    public function rate(string $key): array
    {
        if (!preg_match('#^(\d{1,4})/(\d{1,6})$#', $this->get($key), $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
            throw new ConfigException("$key must look like 5/900");
        }
        return [(int) $m[1], (int) $m[2]];
    }

    public function basePath(): string
    {
        return $this->v['APP_BASE_PATH'];
    }

    /** MT_TIMEOUT in seconds. */
    public function grantSeconds(): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $this->v['MT_TIMEOUT']));
        return $h * 3600 + $m * 60 + $s;
    }

    /** @return list<RouterDef> */
    public function routers(): array
    {
        return $this->routers;
    }
}
