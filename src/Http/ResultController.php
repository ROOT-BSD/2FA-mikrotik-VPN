<?php

declare(strict_types=1);

namespace VpnGw\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Config;
use VpnGw\Log\AuditLogger;
use VpnGw\RouterOs\AccessGranter;
use VpnGw\RouterOs\RouterOsException;

/** "Access granted" page and self-service revoke. */
final class ResultController extends Controller
{
    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        private readonly AccessGranter $granter,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($cfg, $session, $view, $clock);
    }

    public function show(Request $request, Response $response): Response
    {
        if (!$this->inStage('granted', MfaController::RESULT_TTL, $this->clientIp($request))) {
            $this->session->destroy();
            return $this->redirect($response, '/');
        }
        $until = (new \DateTimeImmutable('@' . $this->session->int('grant_until')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $this->view->render($response, 'result', [
            'user' => $this->session->str('user'),
            'ip' => $this->session->str('ip'),
            'until' => $until->format('H:i d.m.Y'),
        ]);
    }

    public function revoke(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);
        if (!$this->inStage('granted', MfaController::RESULT_TTL, $ip) || $ip === null) {
            $this->session->destroy();
            return $this->redirect($response, '/');
        }
        $user = (string) $this->session->str('user');
        try {
            $this->granter->revoke($user, $ip);
            $ok = true;
        } catch (RouterOsException) {
            $ok = false;
        }
        $this->audit->event('revoke_self', $user, $ip, ['ok' => $ok]);
        $this->session->destroy();
        return $ok
            ? $this->view->render($response, 'message', [
                'heading' => 'Доступ відкликано',
                'text' => 'IP-адресу видалено зі списку дозволених, активне VPN-з’єднання розірвано.',
                'kind' => 'success',
            ])
            : $this->view->render($response, 'message', [
                'heading' => 'Не вдалося відкликати доступ',
                'text' => 'Повідомте ІТ-службу: ' . $this->cfg->get('IT_CONTACT') . '. Доступ закриється автоматично після завершення терміну.',
                'kind' => 'error',
            ], 503);
    }
}
