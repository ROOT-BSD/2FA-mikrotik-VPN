<?php

declare(strict_types=1);

namespace VpnGw\Http;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Auth\Authenticator;
use VpnGw\Auth\TotpService;
use VpnGw\Config;
use VpnGw\Log\AuditLogger;
use VpnGw\Security\Crypto;
use VpnGw\Security\RateLimiter;

/**
 * TOTP enrollment for Microsoft Authenticator. Reachable only from internal networks
 * (InternalNetworkOnly), so a stolen password alone cannot bind an attacker's phone.
 */
final class EnrollController extends Controller
{
    private const TTL = 600;
    private const MAX_FAILS = 3;

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

    public function handle(Request $request, Response $response): Response
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($request->getMethod() === 'POST') {
            return match ($this->field($request, 'action', 16)) {
                'login' => $this->login($request, $response, $remote),
                'confirm' => $this->confirm($request, $response, $remote),
                default => $response->withStatus(400),
            };
        }
        return $this->hasPending()
            ? $this->qrPage($response)
            : $this->view->render($response, 'enroll_login');
    }

    private function login(Request $request, Response $response, string $ip): Response
    {
        $user = strtolower(trim($this->field($request, 'username', 64)));
        if ($this->limiter->isLocked("ip:$ip") || ($user !== '' && $this->limiter->isLocked("user:$user"))) {
            return $this->view->render($response, 'enroll_login', ['error' => LoginController::MSG_LOCKED], 429);
        }
        $result = $this->auth->authenticate($user, $this->field($request, 'password', 257));
        if (!$result->ok) {
            $this->audit->event('enroll_fail', $user, $ip, ['reason' => $result->reason]);
            [$limit, $window] = $this->cfg->rate('RATE_LOGIN_USER');
            if ($user !== '' && $this->limiter->hit("user:$user", $limit, $window)) {
                $this->limiter->lock("user:$user", $this->cfg->int('LOCKOUT_SECONDS'), 'enroll');
                $this->audit->event('lockout', $user, $ip, ['key' => 'user', 'stage' => 'enroll']);
            }
            return $this->view->render($response, 'enroll_login', ['error' => LoginController::MSG_BAD_CREDENTIALS], 401);
        }
        if ($this->totp->isEnrolled($result->username)) {
            $this->audit->event('enroll_fail', $result->username, $ip, ['reason' => 'already_enrolled']);
            return $this->view->render($response, 'message', [
                'heading' => 'Автентифікатор уже зареєстровано',
                'text' => 'Повторна реєстрація можлива лише після скидання адміністратором (наприклад, у разі втрати телефона). '
                    . 'Зверніться до ІТ-служби: ' . $this->cfg->get('IT_CONTACT') . '.',
                'kind' => 'warning',
            ], 409);
        }

        $new = $this->totp->newSecret($result->username);
        $this->session->regenerate();
        $this->session->replace([
            'csrf' => Crypto::token(),
            'enroll_user' => $result->username,
            'enroll_secret' => $new['secret'],
            'enroll_uri' => $new['uri'],
            'enroll_ts' => $this->now(),
            'enroll_fails' => 0,
        ]);
        return $this->qrPage($response);
    }

    private function confirm(Request $request, Response $response, string $ip): Response
    {
        if (!$this->hasPending()) {
            $this->session->destroy();
            return $this->view->render($response, 'enroll_login', ['error' => 'Час реєстрації вичерпано. Почніть спочатку.'], 410);
        }
        $user = (string) $this->session->str('enroll_user');
        $secret = (string) $this->session->str('enroll_secret');

        if ($this->totp->confirmEnrollment($user, $secret, $this->field($request, 'code', 16), 'self')) {
            $this->audit->event('enroll_ok', $user, $ip);
            $this->session->destroy();
            return $this->view->render($response, 'message', [
                'heading' => 'Реєстрацію завершено',
                'text' => 'Microsoft Authenticator прив’язано до вашого облікового запису. '
                    . 'Тепер ви можете входити на портал VPN-GW з дому. Порада: увімкніть блокування застосунку в його налаштуваннях.',
                'kind' => 'success',
            ]);
        }

        $fails = $this->session->int('enroll_fails') + 1;
        $this->session->set('enroll_fails', $fails);
        $this->audit->event('enroll_fail', $user, $ip, ['reason' => 'bad_code', 'attempt' => $fails]);
        if ($fails >= self::MAX_FAILS) {
            $this->session->destroy();
            return $this->view->render($response, 'enroll_login', ['error' => 'Забагато невдалих спроб. Почніть реєстрацію спочатку.'], 401);
        }
        return $this->qrPage($response, ['error' => 'Код не підійшов. Перевірте, що час на телефоні встановлюється автоматично.'], 401);
    }

    private function hasPending(): bool
    {
        return $this->session->str('enroll_secret') !== null
            && $this->now() - $this->session->int('enroll_ts') <= self::TTL;
    }

    /** @param array<string, mixed> $vars */
    private function qrPage(Response $response, array $vars = [], int $status = 200): Response
    {
        $qr = new QrCode(
            data: (string) $this->session->str('enroll_uri'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 240,
            margin: 8,
        );
        return $this->view->render($response, 'enroll_qr', $vars + [
            'qr' => (new SvgWriter())->write($qr)->getDataUri(),
            'secret' => TotpService::formatSecret((string) $this->session->str('enroll_secret')),
            'user' => $this->session->str('enroll_user'),
        ], $status);
    }
}
