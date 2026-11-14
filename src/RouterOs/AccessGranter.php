<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

use Psr\Clock\ClockInterface;
use VpnGw\Config;
use VpnGw\Log\AuditLogger;
use VpnGw\Storage\Database;

/**
 * Business logic for opening / closing VPN access on every configured router.
 * Calls are serialized with flock() so parallel requests do not race on the routers.
 */
final class AccessGranter
{
    /** @param list<RouterOsClient> $routers */
    public function __construct(
        private readonly array $routers,
        private readonly string $list,
        private readonly string $timeout,
        private readonly int $grantSeconds,
        private readonly string $lockDir,
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function fromConfig(Config $cfg, Database $db, AuditLogger $audit, ClockInterface $clock): self
    {
        return new self(
            RouterOsFactory::fromConfig($cfg),
            $cfg->get('MT_LIST'),
            $cfg->get('MT_TIMEOUT'),
            $cfg->grantSeconds(),
            $cfg->get('LOCK_DIR'),
            $db,
            $audit,
            $clock,
        );
    }

    /** Only [A-Za-z0-9._-] from the login reaches the router comment. */
    public static function comment(string $username): string
    {
        return 'User: ' . preg_replace('/[^A-Za-z0-9._-]/', '', $username) . ' via VPN-2FA';
    }

    /**
     * Adds (or refreshes the timeout of) the IP on every router.
     *
     * @throws RouterOsException if at least one router failed (details are in the audit log)
     */
    public function grant(string $username, string $ip): \DateTimeImmutable
    {
        $this->assertIp($ip);
        $comment = self::comment($username);
        $failed = $this->eachRouter(function (RouterOsClient $r) use ($ip, $comment, $username): void {
            $id = $r->findEntry($this->list, $ip);
            if ($id === null) {
                $r->addEntry($this->list, $ip, $this->timeout, $comment);
                $this->audit->event('mt_add', $username, $ip, ['router' => $r->name()]);
            } else {
                $r->updateEntry($id, $this->timeout, $comment);
                $this->audit->event('mt_update', $username, $ip, ['router' => $r->name()]);
            }
        }, $username, $ip);

        if ($failed !== []) {
            throw new RouterOsException('Router(s) failed: ' . implode(', ', $failed));
        }
        $now = $this->clock->now();
        $until = $now->modify('+' . $this->grantSeconds . ' seconds');
        $this->db->recordGrant($username, $ip, $now->format(DATE_ATOM), $until->format(DATE_ATOM));
        return $until;
    }

    /**
     * Removes the IP from every router and terminates the user's active VPN sessions.
     *
     * @throws RouterOsException if at least one router failed
     */
    public function revoke(string $username, string $ip): void
    {
        $this->assertIp($ip);
        $failed = $this->eachRouter(function (RouterOsClient $r) use ($ip, $username): void {
            $id = $r->findEntry($this->list, $ip);
            if ($id !== null) {
                $r->removeEntry($id);
            }
            $r->dropSessions($username, $ip);
            $this->audit->event('mt_revoke', $username, $ip, ['router' => $r->name()]);
        }, $username, $ip);

        $this->db->markRevoked($username, $ip, $this->clock->now()->format(DATE_ATOM));
        if ($failed !== []) {
            throw new RouterOsException('Router(s) failed: ' . implode(', ', $failed));
        }
    }

    /**
     * @param callable(RouterOsClient): void $fn
     * @return list<string> names of routers that failed
     */
    private function eachRouter(callable $fn, string $username, string $ip): array
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0700, true) && !is_dir($this->lockDir)) {
            throw new RouterOsException("Lock directory {$this->lockDir} is not available");
        }
        $lock = fopen($this->lockDir . '/routeros.lock', 'c');
        if ($lock === false) {
            throw new RouterOsException('Cannot open lock file');
        }
        $failed = [];
        try {
            flock($lock, LOCK_EX);
            foreach ($this->routers as $router) {
                try {
                    $fn($router);
                } catch (RouterOsException $e) {
                    $failed[] = $router->name();
                    $this->audit->event('mt_error', $username, $ip, ['router' => $router->name(), 'error' => $e->getMessage()]);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $failed;
    }

    private function assertIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException('Refusing to whitelist a non-public or invalid IPv4 address');
        }
    }
}
