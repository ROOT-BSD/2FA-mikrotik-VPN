<?php

declare(strict_types=1);

namespace VpnGw\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Auth\Authenticator;
use VpnGw\Auth\LdapAuthenticator;
use VpnGw\Auth\TotpService;
use VpnGw\Config;
use VpnGw\Log\AuditLogger;
use VpnGw\Security\Crypto;
use VpnGw\Security\RateLimiter;

/** Step 1: domain login and password. */
final class LoginController extends Controller
{
    public const MFA_TTL = 300;
    public const MSG_BAD_CREDENTIALS = 'Невірні облікові дані';
    public const MSG_LOCKED = 'Забагато спроб. Спробуйте пізніше.';

    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        private readonly Authenticator $auth,
        private readonly TotpService $totp,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($cfg, $session, $view, $clock);
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'login');
    }

    public function submit(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);
        $user = LdapAuthenticator::normalizeUsername($this->field($request, 'username', 128), $this->cfg->ldapDomain());
        $pass = $this->field($request, 'password', 257);

        if ($ip === null) {
            $this->audit->event('login_fail', $user, null, ['reason' => 'bad_ip']);
            return $this->view->render($response, 'login', ['error' => self::MSG_BAD_CREDENTIALS], 400);
        }
        // Checked BEFORE contacting AD, so the portal never pushes an account into AD lockout.
        if ($this->limiter->isLocked("ip:$ip") || ($user !== '' && $this->limiter->isLocked("user:$user"))) {
            $this->audit->event('login_blocked', $user, $ip);
            return $this->view->render($response, 'login', ['error' => self::MSG_LOCKED], 429);
        }

        $result = $this->auth->authenticate($user, $pass);
        if (!$result->ok) {
            $this->audit->event('login_fail', $user, $ip, ['reason' => $result->reason]);
            $this->countFailure($user, $ip);
            return $this->view->render($response, 'login', ['error' => self::MSG_BAD_CREDENTIALS], 401);
        }

        $username = $result->username;
        $enrolled = $this->totp->isEnrolled($username);
        $emailOk = $this->cfg->bool('EMAIL_OTP_ENABLED') && $result->mail !== null;
        if (!$enrolled && !$emailOk) {
            $this->audit->event('mfa_not_enrolled', $username, $ip);
            $this->session->destroy();
            return $this->view->render($response, 'message', [
                'heading' => 'Автентифікатор не зареєстровано',
                'text' => 'Щоб користуватися VPN, зареєструйте Microsoft Authenticator в офісі '
                    . '(сторінка реєстрації доступна лише з внутрішньої мережі) або зверніться до ІТ-служби: '
                    . $this->cfg->get('IT_CONTACT') . '.',
                'kind' => 'warning',
            ], 403);
        }

        $this->limiter->clear("user:$username");
        $this->session->regenerate();
        $this->session->replace([
            'csrf' => Crypto::token(),
            'stage' => 'awaiting_2fa',
            'stage_ts' => $this->now(),
            'user' => $username,
            'mail' => $result->mail,
            'ip' => $ip,
            'mfa_fails' => 0,
        ]);
        $this->audit->event('login_ok', $username, $ip);
        return $this->redirect($response, '/verify');
    }

    private function countFailure(string $user, string $ip): void
    {
        $lockFor = $this->cfg->int('LOCKOUT_SECONDS');
        [$ipLimit, $ipWindow] = $this->cfg->rate('RATE_LOGIN_IP');
        if ($this->limiter->hit("ip:$ip", $ipLimit, $ipWindow)) {
            $this->limiter->lock("ip:$ip", $lockFor, 'login');
            $this->audit->event('lockout', $user, $ip, ['key' => 'ip', 'stage' => 'login']);
        }
        if ($user !== '') {
            [$uLimit, $uWindow] = $this->cfg->rate('RATE_LOGIN_USER');
            if ($this->limiter->hit("user:$user", $uLimit, $uWindow)) {
                $this->limiter->lock("user:$user", $lockFor, 'login');
                $this->audit->event('lockout', $user, $ip, ['key' => 'user', 'stage' => 'login']);
            }
        }
    }
}
