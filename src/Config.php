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
 * @phpstan-type RouterDef array{name: string, host: string, version: int, port: int, transport: string, user: string, list: string}
 */
final class Config
{
    private const REQUIRED = [
        'APP_BASE_PATH', 'APP_KEY', 'APP_HMAC_KEY', 'TRUSTED_PROXIES', 'INTERNAL_NETWORKS',
        'LDAP_HOSTS',
        'ROUTERS', 'MT_LIST', 'MT_TIMEOUT',
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
        'MT_USER' => '',
        'MT_CA_FILE' => '',
        'MT_SSH_USER' => '',
        'MT_SSH_PASS' => '',
        'MT_SSH_KEY' => '',
        'MT_SSH_KEY_PASS' => '',
        'MT_SSH_HOSTKEYS' => '{}',
        'ROUTER_PASSWORDS' => '{}',
        'LDAP_SECURITY' => 'ldaps',
        'LDAP_CA_FILE' => '',
        'PRIVATE_CLIENT_NETWORKS' => '',
        'LDAP_DOMAIN' => '',
        'LDAP_VPN_GROUP' => '',
        'LDAP_UPN_SUFFIX' => '',
        'LDAP_BASE_DN' => '',
        'LDAP_VPN_GROUP_DN' => '',
    ];

    /**
     * LDAP connection modes. "-insecure" = encrypted, but the server certificate is not
     * checked (protects against eavesdropping, not against an impostor DC); "plain" = no
     * encryption at all (the user's password crosses the network in clear text).
     */
    public const LDAP_MODES = ['ldaps', 'ldaps-insecure', 'starttls', 'starttls-insecure', 'plain'];

    /** Router access protocols: REST (RouterOS 7) / API-SSL (RouterOS 6), or SSH (both). */
    public const TRANSPORTS = ['api', 'ssh'];

    /** @var array<string, string> */
    private array $v;

    /** @var list<RouterDef> */
    private array $routers = [];

    /** @var array<string, string> per-router passwords (ROUTER_PASSWORDS), by router name */
    private array $routerPasswords = [];

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
        foreach ($this->list('PRIVATE_CLIENT_NETWORKS') as $cidr) {
            if (!Cidr::isValid($cidr)) {
                throw new ConfigException("PRIVATE_CLIENT_NETWORKS: invalid CIDR $cidr");
            }
            if ((int) explode('/', $cidr)[1] < 8) {
                throw new ConfigException("PRIVATE_CLIENT_NETWORKS: $cidr is too wide (at least /8)");
            }
        }
        foreach ($this->list('INTERNAL_NETWORKS') as $cidr) {
            if (!Cidr::isValid($cidr)) {
                throw new ConfigException("INTERNAL_NETWORKS: invalid CIDR $cidr");
            }
        }
        $mode = $this->v['LDAP_SECURITY'];
        if (!in_array($mode, self::LDAP_MODES, true)) {
            throw new ConfigException('LDAP_SECURITY must be one of: ' . implode(', ', self::LDAP_MODES));
        }
        $scheme = str_starts_with($mode, 'ldaps') ? 'ldaps://' : 'ldap://';
        foreach ($this->list('LDAP_HOSTS') as $host) {
            if (!str_starts_with($host, $scheme) || !preg_match('#^ldaps?://[A-Za-z0-9.-]{1,253}(:\d{1,5})?$#', $host)) {
                throw new ConfigException("LDAP_HOSTS: $host must be an {$scheme} URI for LDAP_SECURITY=$mode");
            }
        }
        if ($this->ldapVerifiesCertificate() && trim($this->v['LDAP_CA_FILE']) === '') {
            throw new ConfigException('Missing required setting LDAP_CA_FILE (needed when the certificate is verified)');
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
        foreach (['LDAP_UPN_SUFFIX', 'LDAP_BASE_DN', 'LDAP_VPN_GROUP_DN', 'MT_USER', 'MT_SSH_USER', 'IT_CONTACT', 'SMTP_USER', 'SMTP_FROM'] as $k) {
            if (preg_match('/[\x00-\x1F\x7F]/', $this->v[$k]) === 1) {
                throw new ConfigException("$k contains control characters");
            }
        }
        // AD domain: LDAP_DOMAIN (nerc.gov.ua) derives the UPN suffix and the base DN. The older
        // explicit LDAP_UPN_SUFFIX / LDAP_BASE_DN are used only when LDAP_DOMAIN is empty.
        if ($this->v['LDAP_DOMAIN'] !== '' && !preg_match('/^(?=.{3,200}$)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $this->v['LDAP_DOMAIN'])) {
            throw new ConfigException('LDAP_DOMAIN must look like company.local');
        }
        if ($this->v['LDAP_DOMAIN'] === '') {
            if (!preg_match('/^@[A-Za-z0-9.-]{1,200}$/', $this->v['LDAP_UPN_SUFFIX'])) {
                throw new ConfigException('LDAP_DOMAIN is required (or LDAP_UPN_SUFFIX like @company.local)');
            }
        }
        if ($this->ldapBaseDn() === '') {
            throw new ConfigException('LDAP_DOMAIN is required (or LDAP_BASE_DN)');
        }
        if ($this->v['LDAP_VPN_GROUP'] !== '' && !preg_match('/^[A-Za-z0-9 _.-]{1,64}$/', $this->v['LDAP_VPN_GROUP'])) {
            throw new ConfigException('LDAP_VPN_GROUP: group name (letters, digits, space, _ . -)');
        }
        if ($this->v['LDAP_VPN_GROUP'] === '' && trim($this->v['LDAP_VPN_GROUP_DN']) === '') {
            throw new ConfigException('LDAP_VPN_GROUP is required (VPN group name, e.g. VPN-Users)');
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
        $this->routerPasswords = $this->parseRouterPasswords($this->v['ROUTER_PASSWORDS']);
        // Each router may have its own login and password; empty = the common one of its
        // protocol (MT_USER / MT_PASS for API, MT_SSH_USER / MT_SSH_PASS for SSH).
        $needsCa = false;
        foreach ($this->routers as $r) {
            $c = $this->routerCredentials($r);
            if ($r['transport'] === 'api') {
                $needsCa = true;
                if (trim($c['user']) === '') {
                    throw new ConfigException("Missing required setting MT_USER (REST / API-SSL routers): {$r['name']} has no own user");
                }
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $c['user'])) {
                throw new ConfigException("MT_SSH_USER is required for SSH routers (letters, digits, . _ @ -): {$r['name']}");
            }
            if ($c['password'] === '' && $this->v['MT_SSH_KEY'] === '') {
                throw new ConfigException("SSH routers need MT_SSH_PASS or MT_SSH_KEY: {$r['name']}");
            }
        }
        if ($needsCa && trim($this->v['MT_CA_FILE']) === '') {
            throw new ConfigException('Missing required setting MT_CA_FILE (REST / API-SSL routers)');
        }
        if ($this->v['MT_SSH_KEY'] !== '' && !str_contains($this->v['MT_SSH_KEY'], 'PRIVATE KEY')) {
            throw new ConfigException('MT_SSH_KEY must be a private key (PEM / OpenSSH format)');
        }
        $this->sshHostKeys();
    }

    /** AD DNS domain (nerc.gov.ua): LDAP_DOMAIN, or derived from the legacy LDAP_UPN_SUFFIX. */
    public function ldapDomain(): string
    {
        return strtolower($this->v['LDAP_DOMAIN'] !== '' ? $this->v['LDAP_DOMAIN'] : ltrim($this->v['LDAP_UPN_SUFFIX'], '@'));
    }

    /** "@nerc.gov.ua" — appended to the login for the LDAP bind (user@domain). */
    public function ldapUpnSuffix(): string
    {
        return '@' . $this->ldapDomain();
    }

    /** Search base: DC=nerc,DC=gov,DC=ua derived from the domain (LDAP_BASE_DN only without LDAP_DOMAIN). */
    public function ldapBaseDn(): string
    {
        if ($this->v['LDAP_DOMAIN'] === '' && trim($this->v['LDAP_BASE_DN']) !== '') {
            return trim($this->v['LDAP_BASE_DN']);
        }
        $domain = $this->ldapDomain();
        return $domain === '' ? '' : 'DC=' . implode(',DC=', explode('.', $domain));
    }

    /** VPN group name to look up in AD, or null when a full DN is configured (legacy LDAP_VPN_GROUP_DN). */
    public function ldapGroupName(): ?string
    {
        return $this->v['LDAP_VPN_GROUP'] !== '' ? $this->v['LDAP_VPN_GROUP'] : null;
    }

    public function ldapGroupDn(): ?string
    {
        return $this->v['LDAP_VPN_GROUP'] === '' && trim($this->v['LDAP_VPN_GROUP_DN']) !== '' ? trim($this->v['LDAP_VPN_GROUP_DN']) : null;
    }

    public function ldapVerifiesCertificate(): bool
    {
        return in_array($this->v['LDAP_SECURITY'], ['ldaps', 'starttls'], true);
    }

    /**
     * Pinned SSH host keys of the routers: router name => "SHA256:..." fingerprint.
     *
     * @return array<string, string>
     */
    public function sshHostKeys(): array
    {
        $raw = trim($this->v['MT_SSH_HOSTKEYS']) === '' ? '{}' : $this->v['MT_SSH_HOSTKEYS'];
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ConfigException('MT_SSH_HOSTKEYS must be a JSON object {"router": "SHA256:..."}');
        }
        $out = [];
        foreach ($data as $name => $fp) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name)
                || !is_string($fp) || !preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $fp)) {
                throw new ConfigException('MT_SSH_HOSTKEYS: invalid entry for ' . $name);
            }
            $out[$name] = $fp;
        }
        return $out;
    }

    /**
     * ROUTER_PASSWORDS: JSON object {"router name": "password"}. Entries for routers that
     * are not in ROUTERS are ignored.
     *
     * @return array<string, string>
     */
    private function parseRouterPasswords(string $json): array
    {
        $data = json_decode(trim($json) === '' ? '{}' : $json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ConfigException('ROUTER_PASSWORDS must be a JSON object {"router": "password"}');
        }
        $out = [];
        foreach ($data as $name => $pass) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name) || !is_string($pass) || strlen($pass) > 256) {
                throw new ConfigException('ROUTER_PASSWORDS: invalid entry for ' . $name);
            }
            if ($pass !== '') {
                $out[$name] = $pass;
            }
        }
        return $out;
    }

    /**
     * Login and password the portal uses for this router: its own, or the common one of
     * its protocol when its own is empty.
     *
     * @param RouterDef $r
     * @return array{user: string, password: string, own_user: bool, own_password: bool}
     */
    public function routerCredentials(array $r): array
    {
        $ssh = $r['transport'] === 'ssh';
        $ownPass = $this->routerPasswords[$r['name']] ?? '';
        return [
            'user' => $r['user'] !== '' ? $r['user'] : $this->v[$ssh ? 'MT_SSH_USER' : 'MT_USER'],
            'password' => $ownPass !== '' ? $ownPass : $this->v[$ssh ? 'MT_SSH_PASS' : 'MT_PASS'],
            'own_user' => $r['user'] !== '',
            'own_password' => $ownPass !== '',
        ];
    }

    /**
     * Address-list the portal fills on this router: its own ("list" in ROUTERS) or MT_LIST.
     *
     * @param RouterDef $r
     */
    public function routerList(array $r): string
    {
        return $r['list'] !== '' ? $r['list'] : $this->v['MT_LIST'];
    }

    /** @return array<string, string> router name => effective address-list */
    public function routerLists(): array
    {
        $out = [];
        foreach ($this->routers as $r) {
            $out[$r['name']] = $this->routerList($r);
        }
        return $out;
    }

    /** @return list<string> names of routers that have their own password */
    public function routersWithOwnPassword(): array
    {
        $names = array_column($this->routers, 'name');
        return array_values(array_filter(array_keys($this->routerPasswords), static fn ($n) => in_array($n, $names, true)));
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
                || !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $r['host'])
                || !is_int($r['port'] ?? null) || $r['port'] < 1 || $r['port'] > 65535
                || !in_array($r['transport'] ?? 'api', self::TRANSPORTS, true)) {
                throw new ConfigException("ROUTERS[$i]: need name, host, version (6|7), port, transport (api|ssh)");
            }
            $user = $r['user'] ?? '';
            if (!is_string($user) || ($user !== '' && !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $user))) {
                throw new ConfigException("ROUTERS[$i]: user may contain only letters, digits, . _ @ - (up to 64)");
            }
            $list = $r['list'] ?? '';
            if (!is_string($list) || ($list !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $list))) {
                throw new ConfigException("ROUTERS[$i]: list may contain only letters, digits, _ . - (up to 64)");
            }
            $out[] = ['name' => $r['name'], 'host' => $r['host'], 'version' => $r['version'], 'port' => $r['port'],
                'transport' => $r['transport'] ?? 'api', 'user' => $user, 'list' => $list];
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
