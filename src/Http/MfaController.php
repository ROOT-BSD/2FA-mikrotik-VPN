<?php

declare(strict_types=1);

namespace VpnGw\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Auth\EmailOtp;
use VpnGw\Auth\TotpService;
use VpnGw\Config;
use VpnGw\Log\AuditLogger;
use VpnGw\Mail\Notifier;
use VpnGw\RouterOs\AccessGranter;
use VpnGw\RouterOs\RouterOsException;
use VpnGw\Security\Crypto;
use VpnGw\Security\RateLimiter;

/** Step 2: one-time code, then the IP is written to MikroTik. */
final class MfaController extends Controller
{
    public const RESULT_TTL = 600;

    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        private readonly TotpService $totp,
        private readonly EmailOtp $emailOtp,
        private readonly Notifier $notifier,
        private readonly RateLimiter $limiter,
        private readonly AccessGranter $granter,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($cfg, $session, $view, $clock);
    }

    public function show(Request $request, Response $response): Response
    {
        if (!$this->inStage('awaiting_2fa', LoginController::MFA_TTL, $this->clientIp($request))) {
            return $this->restart($response);
        }
        return $this->page($response);
    }

    public function submit(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);
        if (!$this->inStage('awaiting_2fa', LoginController::MFA_TTL, $ip) || $ip === null) {
            $this->audit->event('mfa_fail', $this->session->str('user'), $ip, ['reason' => 'session_invalid']);
            return $this->restart($response);
        }
        $user = (string) $this->session->str('user');
        $code = $this->field($request, 'code', 16);

        $ok = ($this->totp->isEnrolled($user) && $this->totp->verify($user, $code))
            || ($this->emailOtp->isPending() && $this->emailOtp->verify($code));

        if (!$ok) {
            $fails = $this->session->int('mfa_fails') + 1;
            $this->session->set('mfa_fails', $fails);
            $this->audit->event('mfa_fail', $user, $ip, ['attempt' => $fails]);
            if ($fails >= $this->cfg->int('RATE_MFA_ATTEMPTS')) {
                $lockFor = $this->cfg->int('LOCKOUT_SECONDS');
                $this->limiter->lock("ip:$ip", $lockFor, 'mfa');
                $this->limiter->lock("user:$user", $lockFor, 'mfa');
                $this->audit->event('lockout', $user, $ip, ['key' => 'ip+user', 'stage' => 'mfa']);
                $this->session->destroy();
                return $this->view->render($response, 'login', ['error' => LoginController::MSG_LOCKED], 429);
            }
            return $this->page($response, ['error' => 'Невірний код'], 401);
        }

        $this->audit->event('mfa_ok', $user, $ip);
        try {
            $until = $this->granter->grant($user, $ip);
        } catch (RouterOsException) {
            // Details are already in the audit log (mt_error). The used code cannot be reused.
            return $this->page($response, ['error' => 'Тимчасова помилка. Зачекайте новий код і спробуйте ще раз.'], 503);
        }
        $this->notifier->accessGranted($this->session->str('mail'), $user, $ip, $until);

        $this->session->regenerate();
        $this->session->replace([
            'csrf' => Crypto::token(),
            'stage' => 'granted',
            'stage_ts' => $this->now(),
            'user' => $user,
            'ip' => $ip,
            'grant_until' => $until->getTimestamp(),
        ]);
        return $this->redirect($response, '/result');
    }

    public function sendEmail(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);
        if (!$this->inStage('awaiting_2fa', LoginController::MFA_TTL, $ip) || !$this->emailAllowed()) {
            return $this->restart($response);
        }
        $user = (string) $this->session->str('user');
        [$limit, $window] = $this->cfg->rate('RATE_EMAIL_OTP');
        if ($this->limiter->hit("mail:$user", $limit + 1, $window)) {
            $this->audit->event('email_otp_limited', $user, $ip);
            return $this->page($response, ['error' => 'Ліміт листів вичерпано. Скористайтеся застосунком або спробуйте пізніше.'], 429);
        }
        $sent = $this->notifier->otpCode($this->session->str('mail'), $user, $this->emailOtp->issue());
        $this->audit->event('email_otp_sent', $user, $ip, ['delivered' => $sent]);
        return $sent
            ? $this->page($response, ['info' => 'Код надіслано на вашу робочу пошту. Він дійсний 5 хвилин.'])
            : $this->page($response, ['error' => 'Не вдалося надіслати лист. Спробуйте пізніше.'], 503);
    }

    /** @param array<string, mixed> $vars */
    private function page(Response $response, array $vars = [], int $status = 200): Response
    {
        $user = (string) $this->session->str('user');
        return $this->view->render($response, 'mfa', $vars + [
            'enrolled' => $this->totp->isEnrolled($user),
            'emailAllowed' => $this->emailAllowed(),
            'emailPending' => $this->emailOtp->isPending(),
        ], $status);
    }

    private function emailAllowed(): bool
    {
        return $this->cfg->bool('EMAIL_OTP_ENABLED') && $this->notifier->enabled() && $this->session->str('mail') !== null;
    }

    private function restart(Response $response): Response
    {
        $this->session->destroy();
        return $this->redirect($response, '/');
    }
}
