<?php

declare(strict_types=1);

namespace VpnGw\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Security audit log: one JSON line per event, to file and syslog (LOG_LOCAL5 -> SIEM).
 * Secrets never reach the log: context keys that look like passwords, codes or tokens
 * are dropped, and client-supplied strings are stripped of control characters and cut.
 */
final class AuditLogger
{
    private const WARN_EVENTS = [
        'login_fail', 'mfa_fail', 'lockout', 'mt_error', 'enroll_fail', 'mail_error', 'unhandled', 'mfa_not_enrolled',
    ];
    private const FORBIDDEN_KEY = '/pass|code|secret|otp|token|csrf|session/i';

    /** @param array<string, mixed> $server */
    public function __construct(
        private readonly LoggerInterface $logger,
        private array $server = [],
    ) {
    }

    public static function create(string $logPath): self
    {
        $logger = new Logger('vpn-gw');
        $file = new StreamHandler($logPath, Level::Info, true, 0600);
        $file->setFormatter(new JsonFormatter());
        $logger->pushHandler($file);
        $syslog = new SyslogHandler('vpn-gw', LOG_LOCAL5, Level::Info);
        $syslog->setFormatter(new JsonFormatter());
        $logger->pushHandler($syslog);
        return new self($logger);
    }

    public static function null(): self
    {
        return new self(new NullLogger());
    }

    /** @param array<string, mixed> $server request server params ($_SERVER) */
    public function setRequestContext(array $server): void
    {
        $this->server = $server;
    }

    /** @param array<string, scalar|null> $extra */
    public function event(string $event, ?string $user, ?string $ip, array $extra = []): void
    {
        $ctx = [
            'event' => $event,
            'app_version' => \VpnGw\Version::CURRENT,
            'user' => $user,
            'ip' => $ip,
            'remote_addr' => self::clean($this->server['REMOTE_ADDR'] ?? null, 64),
            'xff_raw' => self::clean($this->server['HTTP_X_FORWARDED_FOR'] ?? null, 256),
            'ua' => self::clean($this->server['HTTP_USER_AGENT'] ?? null, 256),
        ];
        foreach ($extra as $k => $v) {
            if (!preg_match(self::FORBIDDEN_KEY, $k)) {
                $ctx[$k] = is_string($v) ? self::clean($v, 512) : $v;
            }
        }
        $level = in_array($event, self::WARN_EVENTS, true) ? 'warning' : 'info';
        $this->logger->log($level, $event, array_filter($ctx, static fn ($v) => $v !== null));
    }

    public function psr(): LoggerInterface
    {
        return $this->logger;
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return mb_substr($value, 0, $max);
    }
}
