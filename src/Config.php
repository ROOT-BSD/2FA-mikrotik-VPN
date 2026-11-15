<?php

declare(strict_types=1);

namespace VpnGw;

use Dotenv\Dotenv;
use VpnGw\Security\Cidr;
use VpnGw\Security\Crypto;
use VpnGw\Settings\SettingsSchema;
use VpnGw\Settings\SettingsStore;
use VpnGw\Storage\Database;

/**
 * Validated application configuration. Fails closed: any missing or malformed
 * value throws ConfigException at startup instead of degrading security later.
 *
 * Sources, lowest to highest priority: built-in defaults -> .env -> values saved in
 * the admin panel (SQLite "settings" table, only keys from SettingsSchema).
 *
 * @phpstan-type RouterDef array{name: string, host: string, version: int, port: int}
 */
final class Config
{
    private const REQUIRED = [
        'APP_BASE_PATH', 'APP_KEY', 'APP_HMAC_KEY', 'TRUSTED_PROXIES', 'INTERNAL_NETWORKS',
        'LDAP_HOSTS', 'LDAP_UPN_SUFFIX', 'LDAP_BASE_DN', 'LDAP_VPN_GROUP_DN', 'LDAP_CA_FILE',
        'ROUTERS', 'MT_USER', 'MT_CA_FILE', 'MT_LIST', 'MT_TIMEOUT',
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
        'DATA_DIR' => '/var/lib/vpn-gw',
        'AGENT_DIR' => '/var/lib/vpn-gw-agent',
        'BACKUP_DIR' => '/var/backups/vpn-gw',
        'LOG_LEVEL' => 'info',
        'LOG_EVENTS' => 'auth,access,enroll,mail,admin,system',
        'LOG_SYSLOG' => 'true',
        'LOG_SYSLOG_FACILITY' => 'local5',
        'LOG_REMOTE' => '',
        'LOG_RETENTION_DAYS' => '180',
        'BACKUP_ENABLED' => 'true',
        'BACKUP_HOUR' => '2',
        'BACKUP_KEEP' => '14',
        'BACKUP_PASSPHRASE' => '',
        'MT_PASS' => '',
    ];

    /** @var array<string, string> */
    private array $v;

    /** @var list<RouterDef> */
    private array $routers = [];

    /**
     * @param array<string, string> $base defaults + .env
     * @param array<string, string> $panel admin-panel overrides
     */
    private function __construct(private readonly array $base, private readonly array $panel)
    {
        $this->v = $panel + $base + self::DEFAULTS;
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
        return self::fromArray($values, self::panelValues($values));
    }

    /**
     * @param array<string, string> $values .env values
     * @param array<string, string> $panel admin-panel overrides (editable keys only)
     */
    public static function fromArray(array $values, array $panel = []): self
    {
        $panel = array_intersect_key($panel, array_flip(SettingsSchema::editableKeys()));
        $cfg = new self($values, $panel);
        $cfg->validate();
        return $cfg;
    }

    /**
     * Same configuration with some panel values replaced; validated, so the admin
     * panel can refuse a change before saving it.
     *
     * @param array<string, string> $changes
     */
    public function withPanelValues(array $changes): self
    {
        return self::fromArray($this->base, $changes + $this->panel);
    }

    /** Where the effective value comes from: panel | env | default. */
    public function source(string $key): string
    {
        return match (true) {
            array_key_exists($key, $this->panel) => 'panel',
            array_key_exists($key, $this->base) => 'env',
            default => 'default',
        };
    }

    /** Value from .env / defaults, ignoring the panel. */
    public function baseValue(string $key): string
    {
        return $this->base[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    /**
     * Reads panel overrides from the database named in .env. No database yet
     * (fresh install) means no overrides; an unreadable secret is fatal.
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private static function panelValues(array $env): array
    {
        $path = $env['DB_PATH'] ?? '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }
        $cfg = new self($env, []);
        $enc = $cfg->key('APP_KEY');
        $mac = $cfg->key('APP_HMAC_KEY');
        if (strlen($enc) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || strlen($mac) < 32) {
            return []; // validate() reports the bad key
        }
        try {
            return (new SettingsStore(new Database($path), new Crypto($enc, $mac)))->values();
        } catch (\PDOException $e) {
            return [];
        } catch (\RuntimeException $e) {
            throw new ConfigException('Cannot decrypt settings saved in the admin panel (APP_KEY changed?): ' . $e->getMessage());
        }
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
        foreach (['LDAP_UPN_SUFFIX', 'LDAP_BASE_DN', 'LDAP_VPN_GROUP_DN', 'MT_USER', 'IT_CONTACT', 'SMTP_USER', 'SMTP_FROM'] as $k) {
            if (preg_match('/[\x00-\x1F\x7F]/', $this->v[$k]) === 1) {
                throw new ConfigException("$k contains control characters");
            }
        }
        if (!preg_match('/^@[A-Za-z0-9.-]{1,200}$/', $this->v['LDAP_UPN_SUFFIX'])) {
            throw new ConfigException('LDAP_UPN_SUFFIX must look like @company.local');
        }
        if ($this->v['SMTP_HOST'] !== '' && !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $this->v['SMTP_HOST'])) {
            throw new ConfigException('SMTP_HOST must be a host name or IP address');
        }
        $this->intRange('SMTP_PORT', 1, 65535);
        if ($this->v['SMTP_FROM'] !== '' && filter_var($this->v['SMTP_FROM'], FILTER_VALIDATE_EMAIL) === false) {
            throw new ConfigException('SMTP_FROM must be an e-mail address');
        }
        foreach (['EMAIL_OTP_ENABLED', 'LOG_SYSLOG', 'BACKUP_ENABLED'] as $k) {
            if (filter_var($this->v[$k], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
                throw new ConfigException("$k must be true or false");
            }
        }
        if (!str_starts_with($this->v['LOG_PATH'], '/') && !str_starts_with($this->v['LOG_PATH'], 'php://')) {
            throw new ConfigException('LOG_PATH must be an absolute path');
        }
        if (!in_array($this->v['LOG_LEVEL'], ['info', 'warning'], true)) {
            throw new ConfigException('LOG_LEVEL must be info or warning');
        }
        if (!preg_match('/^local[0-7]$/', $this->v['LOG_SYSLOG_FACILITY'])) {
            throw new ConfigException('LOG_SYSLOG_FACILITY must be local0..local7');
        }
        foreach ($this->list('LOG_EVENTS') as $cat) {
            if (!array_key_exists($cat, SettingsSchema::LOG_CATEGORIES)) {
                throw new ConfigException("LOG_EVENTS: unknown category $cat");
            }
        }
        if ($this->v['LOG_REMOTE'] !== '') {
            $this->remoteSyslog();
        }
        $this->intRange('LOG_RETENTION_DAYS', 1, 3650);
        $this->intRange('BACKUP_HOUR', 0, 23);
        $this->intRange('BACKUP_KEEP', 1, 365);
        $pass = $this->v['BACKUP_PASSPHRASE'];
        if ($pass !== '' && mb_strlen($pass) < 12) {
            throw new ConfigException('BACKUP_PASSPHRASE must be at least 12 characters');
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

    /** @return array{0: string, 1: int} [host, port] of LOG_REMOTE */
    public function remoteSyslog(): array
    {
        if (!preg_match('/^([A-Za-z0-9.-]{1,253}):(\d{1,5})$/', $this->get('LOG_REMOTE'), $m)
            || (int) $m[2] < 1 || (int) $m[2] > 65535) {
            throw new ConfigException('LOG_REMOTE must look like siem.company.local:514');
        }
        return [$m[1], (int) $m[2]];
    }

    private function intRange(string $key, int $min, int $max): void
    {
        $v = $this->int($key);
        if ($v < $min || $v > $max) {
            throw new ConfigException("$key must be between $min and $max");
        }
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
