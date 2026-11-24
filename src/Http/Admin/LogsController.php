<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use VpnGw\Admin\LogReader;
use VpnGw\Config;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Settings\SettingsSchema;

final class LogsController extends AdminController
{
    /** file=* — «Показати всі»: all log files together. */
    public const ALL = '*';

    /** Entries shown at most for «Показати всі». */
    public const ALL_LIMIT = 1000;

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
            $files = $this->reader->files();
            $file = is_string($q['file'] ?? null) && ($q['file'] === self::ALL || in_array($q['file'], array_column($files, 'name'), true)) ? $q['file'] : '';
            if ($file === basename($this->cfg->get('LOG_PATH'))) {
                $file = '';
            }
            return $this->page($response, 'admin_logs', 'logs', [
                'entries' => $file === self::ALL ? $this->reader->tailAll(self::ALL_LIMIT, $filter) : $this->reader->tail(300, $filter, file: $file),
                'allLimit' => self::ALL_LIMIT,
                'filter' => $filter,
                'files' => $files,
                'file' => $file,
                'categories' => SettingsSchema::LOG_CATEGORIES,
                'logPath' => $this->cfg->get('LOG_PATH'),
                'syslog' => $this->cfg->bool('LOG_SYSLOG') ? $this->cfg->get('LOG_SYSLOG_FACILITY') : '',
                'remote' => $this->cfg->get('LOG_REMOTE'),
            ]);
        });
    }

    /**
     * «Завантажити»: the selected log file as it is on disk (a .gz stays compressed). Only a
     * name from the list of log files is accepted.
     */
    public function download(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response): Response {
            $name = $this->query($request)['file'] ?? '';
            $path = is_string($name) ? $this->reader->pathOf($name === '' ? basename($this->cfg->get('LOG_PATH')) : $name) : null;
            $fh = $path === null || !is_file($path) ? false : fopen($path, 'rb');
            if ($path === null || $fh === false) {
                return $response->withStatus(404);
            }
            $this->audit->event('admin_log_download', $admin, $this->remote($request), ['file' => basename($path)]);
            return $response
                ->withBody(new Stream($fh))
                ->withHeader('Content-Type', str_ends_with($path, '.gz') ? 'application/gzip' : 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
                ->withHeader('Content-Length', (string) filesize($path))
                ->withHeader('X-Content-Type-Options', 'nosniff');
        });
    }
}
