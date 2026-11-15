<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Config;
use VpnGw\Http\Controller;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;

/**
 * Base for admin-panel pages. The whole /admin tree is reachable only from
 * INTERNAL_NETWORKS (InternalNetworkOnly middleware, REMOTE_ADDR only); in addition
 * the session must belong to a logged-in administrator from the same address.
 */
abstract class AdminController extends Controller
{
    /** Idle and absolute session lifetime of an administrator. */
    public const IDLE_SECONDS = 1800;
    public const MAX_SECONDS = 28800;

    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        protected readonly AuditLogger $audit,
    ) {
        parent::__construct($cfg, $session, $view, $clock);
    }

    /** Logged-in administrator, or null (also ends an expired session). */
    protected function admin(Request $request): ?string
    {
        $user = $this->session->str('admin_user');
        if ($user === null) {
            return null;
        }
        $now = $this->now();
        $valid = hash_equals((string) $this->session->str('admin_ip'), $this->remote($request))
            && $now - $this->session->int('admin_seen') <= self::IDLE_SECONDS
            && $now - $this->session->int('admin_login') <= self::MAX_SECONDS;
        if (!$valid) {
            $this->session->remove('admin_user', 'admin_ip', 'admin_seen', 'admin_login');
            return null;
        }
        $this->session->set('admin_seen', $now);
        return $user;
    }

    /**
     * Runs $page for a logged-in administrator; otherwise redirects to the login form.
     *
     * @param callable(string): Response $page
     */
    protected function guarded(Request $request, Response $response, callable $page): Response
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->redirect($response, '/admin/login');
        }
        return $page($admin);
    }

    /** @param array<string, mixed> $vars */
    protected function page(Response $response, string $template, string $nav, array $vars = [], int $status = 200): Response
    {
        return $this->view->render($response, $template, $vars + [
            'layout' => 'admin_layout',
            'nav' => $nav,
            'admin' => $this->session->str('admin_user'),
            'flash' => $this->takeFlash(),
            'title' => 'VPN-GW — адміністрування',
        ], $status);
    }

    protected function flash(string $text, string $kind = 'success'): void
    {
        $this->session->set('admin_flash', ['text' => $text, 'kind' => $kind]);
    }

    /** @return array{text: string, kind: string}|null */
    private function takeFlash(): ?array
    {
        $f = $this->session->get('admin_flash');
        $this->session->remove('admin_flash');
        return is_array($f) && is_string($f['text'] ?? null) && is_string($f['kind'] ?? null)
            ? ['text' => $f['text'], 'kind' => $f['kind']] : null;
    }

    /** The admin panel trusts only the TCP peer address (internal network). */
    protected function remote(Request $request): string
    {
        $r = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        return is_string($r) ? $r : '';
    }

    /** @return array<string, string> query parameters as strings */
    protected function query(Request $request): array
    {
        $out = [];
        foreach ($request->getQueryParams() as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = mb_substr($v, 0, 200);
            }
        }
        return $out;
    }
}
