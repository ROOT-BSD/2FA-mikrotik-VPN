<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Admin\LogReader;
use VpnGw\Config;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Settings\SettingsSchema;

final class LogsController extends AdminController
{
    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        AuditLogger $audit,
        private readonly LogReader $reader,
    ) {
        parent::__construct($cfg, $session, $view, $clock, $audit);
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function () use ($request, $response): Response {
            $q = $this->query($request);
            $filter = [
                'q' => $q['q'] ?? '',
                'level' => in_array($q['level'] ?? '', ['info', 'warning', 'error'], true) ? $q['level'] : '',
                'category' => array_key_exists($q['category'] ?? '', SettingsSchema::LOG_CATEGORIES) ? $q['category'] : '',
            ];
            return $this->page($response, 'admin_logs', 'logs', [
                'entries' => $this->reader->tail(300, $filter),
                'filter' => $filter,
                'categories' => SettingsSchema::LOG_CATEGORIES,
                'logPath' => $this->cfg->get('LOG_PATH'),
                'syslog' => $this->cfg->bool('LOG_SYSLOG') ? $this->cfg->get('LOG_SYSLOG_FACILITY') : '',
                'remote' => $this->cfg->get('LOG_REMOTE'),
            ]);
        });
    }
}
