<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

/**
 * RouterOS 6/7 over SSH (console commands through the exec channel).
 *
 * Unlike REST / API-SSL, SSH carries CLI text, so every value is checked against a strict
 * whitelist before it is put into a command; anything else is refused, not escaped.
 * The router's host key must match the fingerprint pinned in the admin panel
 * (MT_SSH_HOSTKEYS): without a pinned key the portal refuses to connect, unless the
 * client was created for the panel's «Перевірити» button (trust on first use there,
 * with the administrator looking at the fingerprint).
 */
final class SshClient implements RouterOsClient
{
    private ?SshSession $session = null;
    private ?string $seenFingerprint = null;

    /** @param \Closure(): SshSession $sessionFactory */
    public function __construct(
        private readonly string $name,
        private readonly \Closure $sessionFactory,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        #[\SensitiveParameter] private readonly string $privateKey,
        #[\SensitiveParameter] private readonly string $keyPassphrase,
        private readonly ?string $pinnedFingerprint,
        private readonly bool $trustOnFirstUse = false,
    ) {
    }

    public static function create(string $name, string $host, int $port, string $user, string $password, string $privateKey,
        string $keyPassphrase, ?string $pinned, bool $trustOnFirstUse = false): self
    {
        return new self($name, static fn (): SshSession => new PhpseclibSession($host, $port), $user, $password, $privateKey,
            $keyPassphrase, $pinned, $trustOnFirstUse);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Host key fingerprint seen on the last connection (for the admin panel). */
    public function seenFingerprint(): ?string
    {
        return $this->seenFingerprint;
    }

    public function findEntry(string $list, string $ip): ?string
    {
        $out = trim($this->exec(':put [:tostr [/ip firewall address-list find list=' . self::str($list, 'list') . ' address=' . self::quotedIp($ip) . ']]'));
        if ($out === '') {
            return null;
        }
        if (!preg_match('/^\*[0-9A-Fa-f]{1,16}(;\*[0-9A-Fa-f]{1,16})*$/', $out)) {
            throw new RouterOsException("{$this->name}: SSH: unexpected answer: " . self::short($out));
        }
        return explode(';', $out)[0];
    }

    public function addEntry(string $list, string $ip, string $timeout, string $comment): void
    {
        $this->command('/ip firewall address-list add list=' . self::str($list, 'list') . ' address=' . self::quotedIp($ip)
            . ' timeout=' . self::timeout($timeout) . ' comment=' . self::str($comment, 'comment'));
    }

    public function updateEntry(string $id, string $timeout, string $comment): void
    {
        $this->command('/ip firewall address-list set ' . self::id($id) . ' timeout=' . self::timeout($timeout)
            . ' comment=' . self::str($comment, 'comment'));
    }

    public function removeEntry(string $id): void
    {
        $this->command('/ip firewall address-list remove ' . self::id($id));
    }

    public function dropSessions(string $username, string $ip): void
    {
        $this->command('/ppp active remove [find name=' . self::str($username, 'user') . ']');
        $this->command('/ip ipsec active-peers remove [find remote-address=' . self::ip($ip) . ']');
    }

    /**
     * Fixed RouterOS script (no user values in it). Entries are counted on the router, so a
     * large list costs one output line, not one line per address. Each rule table is in its
     * own :do/on-error (no "raw" table before 6.33).
     */
    public const LISTS_SCRIPT = ':local a [:toarray ""]; '
        . ':foreach i in=[/ip firewall address-list find] do={:local n [/ip firewall address-list get $i list]; :local c ($a->$n); '
        . ':if ([:typeof $c] = "num") do={:set ($a->$n) ($c + 1)} else={:set ($a->$n) 1}}; '
        . ':foreach k,v in=$a do={:put ("E " . $v . " " . $k)}; '
        . ':do {:foreach i in=[/ip firewall filter find] do={:put ("R " . [/ip firewall filter get $i src-address-list]); :put ("R " . [/ip firewall filter get $i dst-address-list])}} on-error={}; '
        . ':do {:foreach i in=[/ip firewall nat find] do={:put ("R " . [/ip firewall nat get $i src-address-list]); :put ("R " . [/ip firewall nat get $i dst-address-list])}} on-error={}; '
        . ':do {:foreach i in=[/ip firewall mangle find] do={:put ("R " . [/ip firewall mangle get $i src-address-list]); :put ("R " . [/ip firewall mangle get $i dst-address-list])}} on-error={}; '
        . ':do {:foreach i in=[/ip firewall raw find] do={:put ("R " . [/ip firewall raw get $i src-address-list]); :put ("R " . [/ip firewall raw get $i dst-address-list])}} on-error={}; '
        . ':put "END"';

    public function addressLists(): array
    {
        $out = $this->exec(self::LISTS_SCRIPT);
        $lines = preg_split('/\r?\n/', $out) ?: [];
        $entries = [];
        $refs = [];
        $end = false;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match('/^E (\d{1,9}) (.+)$/', $line, $m) === 1) {
                $entries[$m[2]] = (int) $m[1];
            } elseif (str_starts_with($line, 'R ')) {
                $refs[] = substr($line, 2);
            } elseif ($line === 'END') {
                $end = true;
            }
        }
        if (!$end) {
            throw new RouterOsException("{$this->name}: SSH: unexpected answer: " . self::short($out));
        }
        return AddressLists::build($entries, $refs);
    }

    public function close(): void
    {
        $this->session?->disconnect();
        $this->session = null;
    }

    /** A command that prints nothing on success; any output is RouterOS's error message. */
    private function command(string $cmd): void
    {
        $out = trim($this->exec($cmd));
        if ($out !== '') {
            throw new RouterOsException("{$this->name}: SSH: " . self::short($out));
        }
    }

    private function exec(string $cmd): string
    {
        try {
            return $this->session()->exec($cmd);
        } catch (RouterOsException $e) {
            $this->close();
            throw new RouterOsException("{$this->name}: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $this->close();
            throw new RouterOsException("{$this->name}: SSH: " . $e->getMessage(), 0, $e);
        }
    }

    private function session(): SshSession
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $session = ($this->sessionFactory)();
        $fp = $session->hostKeyFingerprint();
        $this->seenFingerprint = $fp;
        if ($this->pinnedFingerprint === null) {
            if (!$this->trustOnFirstUse) {
                $session->disconnect();
                throw new RouterOsException('SSH host key is not confirmed yet: admin panel → MikroTik → «Перевірити MikroTik»');
            }
        } elseif (!hash_equals($this->pinnedFingerprint, $fp)) {
            $session->disconnect();
            throw new RouterOsException("SSH host key CHANGED (expected {$this->pinnedFingerprint}, got $fp). Possible impostor; if the router was really replaced, reset the key in the admin panel");
        }
        // "+cet": no colours, dumb terminal, no capability detection — clean output for automation.
        $login = str_contains($this->user, '+') ? $this->user : $this->user . '+cet';
        if (!$session->login($login, $this->password, $this->privateKey, $this->keyPassphrase)) {
            $session->disconnect();
            throw new RouterOsException('SSH login failed (user, password or key)');
        }
        return $this->session = $session;
    }

    // ---- strict value checks: whitelist, never escaping ---------------------------------

    private static function str(string $v, string $kind): string
    {
        $re = match ($kind) {
            'list' => '/^[A-Za-z0-9_.-]{1,64}$/',
            'user' => '/^[A-Za-z0-9_.-]{1,64}$/',
            default => '/^[A-Za-z0-9 _.:-]{1,128}$/',
        };
        if (!preg_match($re, $v)) {
            throw new RouterOsException("SSH: refused unsafe $kind value");
        }
        return '"' . $v . '"';
    }

    private static function ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RouterOsException('SSH: refused invalid IPv4 address');
        }
        return $ip;
    }

    /** address-list "address" is a string property (it may hold a DNS name): compare as a string. */
    private static function quotedIp(string $ip): string
    {
        return '"' . self::ip($ip) . '"';
    }

    private static function timeout(string $t): string
    {
        if (!preg_match('/^\d{1,3}:[0-5]\d:[0-5]\d$/', $t)) {
            throw new RouterOsException('SSH: refused invalid timeout');
        }
        return $t;
    }

    private static function id(string $id): string
    {
        if (!preg_match('/^\*[0-9A-Fa-f]{1,16}$/', $id)) {
            throw new RouterOsException('SSH: refused invalid item id');
        }
        return $id;
    }

    private static function short(string $out): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $out), 0, 200);
    }
}
