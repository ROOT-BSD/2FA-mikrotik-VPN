<?php

declare(strict_types=1);

namespace VpnGw\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VpnGw\Config;
use VpnGw\Settings\SettingsSchema;

/**
 * Security audit log: one JSON line per event, to a file, local syslog and optionally a
 * remote syslog/SIEM over UDP. Destinations, level and event categories come from
 * the configuration (admin panel -> «Журнали»).
 * Secrets never reach the log: context keys that look like passwords, codes or tokens
 * are dropped, and client-supplied strings are stripped of control characters and cut.
 */
final class AuditLogger
{
    private const WARN_EVENTS = [
        'login_fail', 'mfa_fail', 'lockout', 'mt_error', 'enroll_fail', 'mail_error', 'unhandled', 'mfa_not_enrolled',
        'admin_login_fail', 'admin_lockout', 'backup_error', 'update_error',
    ];
    private const FORBIDDEN_KEY = '/pass|code|secret|otp|token|csrf|session/i';

    /**
     * @param array<string, mixed> $server
     * @param list<string>|null $categories categories to write (null = all)
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private array $server = [],
        private readonly ?array $categories = null,
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

    public static function fromConfig(Config $cfg): self
    {
        $level = $cfg->get('LOG_LEVEL') === 'warning' ? Level::Warning : Level::Info;
        $logger = new Logger('vpn-gw');
        $file = new StreamHandler($cfg->get('LOG_PATH'), $level, true, 0600);
        $file->setFormatter(new JsonFormatter());
        $logger->pushHandler($file);
        $facility = self::facility($cfg->get('LOG_SYSLOG_FACILITY'));
        if ($cfg->bool('LOG_SYSLOG')) {
            $syslog = new SyslogHandler('vpn-gw', $facility, $level);
            $syslog->setFormatter(new JsonFormatter());
            $logger->pushHandler($syslog);
        }
        if ($cfg->get('LOG_REMOTE') !== '') {
            [$host, $port] = $cfg->remoteSyslog();
            $remote = new SyslogUdpHandler($host, $port, $facility, $level, true, 'vpn-gw', SyslogUdpHandler::RFC5424);
            $remote->setFormatter(new JsonFormatter());
            // A dead SIEM must never break logins: swallow transport errors of the remote handler.
            $logger->pushHandler(new WhatFailureGroupHandler([$remote]));
        }
        $categories = array_values(array_unique([...$cfg->list('LOG_EVENTS'), 'admin', 'system']));
        return new self($logger, [], $categories);
    }

    private static function facility(string $name): int
    {
        return [
            'local0' => LOG_LOCAL0, 'local1' => LOG_LOCAL1, 'local2' => LOG_LOCAL2, 'local3' => LOG_LOCAL3,
            'local4' => LOG_LOCAL4, 'local5' => LOG_LOCAL5, 'local6' => LOG_LOCAL6, 'local7' => LOG_LOCAL7,
        ][$name] ?? LOG_LOCAL5;
    }

    public function enabled(string $event): bool
    {
        return $this->categories === null || in_array(SettingsSchema::eventCategory($event), $this->categories, true);
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
        if (!$this->enabled($event)) {
            return;
        }
        $ctx = [
            'event' => $event,
            'category' => SettingsSchema::eventCategory($event),
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
