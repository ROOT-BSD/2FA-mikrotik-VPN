<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Auth\LdapAuthenticator;
use VpnGw\Config;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Storage\Database;

/**
 * «Автентифікатори»: the registered Microsoft Authenticator bindings, with deletion.
 * Deleting a binding is what bin/totp-reset.php does: the user has to enroll again from
 * the internal network before the next VPN login. An access already granted is not touched.
 */
final class AuthenticatorsController extends AdminController
{
    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        AuditLogger $audit,
        private readonly Database $db,
    ) {
        parent::__construct($cfg, $session, $view, $clock, $audit);
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function () use ($request, $response): Response {
            $q = trim($this->query($request)['q'] ?? '');
            $q = preg_replace('/[^A-Za-z0-9._@-]/', '', $q) ?? '';
            $now = $this->clock->now()->format(DATE_ATOM);
            $rows = [];
            $emails = $this->db->userEmails();
            foreach ($this->db->listTotp($q) as $r) {
                $rows[] = $r + [
                    // last_step is the 30-second TOTP step of the last accepted code (enrolment included)
                    'last_used' => $r['last_step'] > 0 ? $r['last_step'] * 30 : null,
                    'active' => $this->db->hasActiveGrantFor($r['username'], $now),
                    'email' => $emails[$r['username']] ?? '',
                ];
            }
            return $this->page($response, 'admin_authenticators', 'authenticators', [
                'rows' => $rows,
                'q' => $q,
                'total' => $this->db->countTotp(),
            ]);
        });
    }

    public function delete(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response): Response {
            $body = $request->getParsedBody();
            $user = is_array($body) && is_string($body['username'] ?? null) ? $body['username'] : '';
            if (!LdapAuthenticator::isValidUsername($user) || !$this->db->deleteTotp($user)) {
                $this->flash('Такого автентифікатора вже немає.', 'warning');
                return $this->redirect($response, '/admin/authenticators');
            }
            $this->audit->event('admin_totp_reset', $admin, $this->remote($request), ['target' => $user]);
            $this->flash("Автентифікатор користувача {$user} видалено. Щоб підключатися до VPN, він має зареєструватися знову з внутрішньої мережі.");
            return $this->redirect($response, '/admin/authenticators');
        });
    }
}
