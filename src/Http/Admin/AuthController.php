<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Admin\AdminAuth;
use VpnGw\Config;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Security\Crypto;
use VpnGw\Security\RateLimiter;

/** Administrator login, logout and password change. */
final class AuthController extends AdminController
{
    private const MSG_BAD = 'Неправильний логін або пароль.';
    private const MSG_LOCKED = 'Забагато невдалих спроб. Спробуйте пізніше.';
    private const USER_LIMIT = [5, 900];

    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        AuditLogger $audit,
        private readonly AdminAuth $auth,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($cfg, $session, $view, $clock, $audit);
    }

    public function form(Request $request, Response $response): Response
    {
        if ($this->admin($request) !== null) {
            return $this->redirect($response, '/admin');
        }
        return $this->view->render($response, 'admin_login', ['title' => 'VPN-GW — адміністрування']);
    }

    public function login(Request $request, Response $response): Response
    {
        $ip = $this->remote($request);
        $user = strtolower(trim($this->field($request, 'username', 64)));
        if ($this->limiter->isLocked("admin-ip:$ip") || ($user !== '' && $this->limiter->isLocked("admin-user:$user"))) {
            $this->audit->event('admin_login_blocked', $user, $ip);
            return $this->view->render($response, 'admin_login', ['error' => self::MSG_LOCKED], 429);
        }
        if (!$this->auth->verify($user, $this->field($request, 'password', 257))) {
            $this->audit->event('admin_login_fail', $user, $ip);
            [$ipLimit, $ipWindow] = $this->cfg->rate('RATE_LOGIN_IP');
            $lockFor = $this->cfg->int('LOCKOUT_SECONDS');
            if ($this->limiter->hit("admin-ip:$ip", $ipLimit, $ipWindow)) {
                $this->limiter->lock("admin-ip:$ip", $lockFor, 'admin');
                $this->audit->event('admin_lockout', $user, $ip, ['key' => 'ip']);
            }
            if ($user !== '' && $this->limiter->hit("admin-user:$user", self::USER_LIMIT[0], self::USER_LIMIT[1])) {
                $this->limiter->lock("admin-user:$user", $lockFor, 'admin');
                $this->audit->event('admin_lockout', $user, $ip, ['key' => 'user']);
            }
            return $this->view->render($response, 'admin_login', ['error' => self::MSG_BAD], 401);
        }
        $this->limiter->clear("admin-user:$user");
        $this->auth->recordLogin($user, $ip);
        $this->session->regenerate();
        $now = $this->now();
        $this->session->replace([
            'csrf' => Crypto::token(),
            'admin_user' => $user,
            'admin_ip' => $ip,
            'admin_login' => $now,
            'admin_seen' => $now,
        ]);
        $this->audit->event('admin_login_ok', $user, $ip);
        return $this->redirect($response, '/admin');
    }

    public function logout(Request $request, Response $response): Response
    {
        $admin = $this->admin($request);
        if ($admin !== null) {
            $this->audit->event('admin_logout', $admin, $this->remote($request));
        }
        $this->session->destroy();
        return $this->redirect($response, '/admin/login');
    }

    public function password(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response): Response {
            if ($request->getMethod() !== 'POST') {
                return $this->page($response, 'admin_password', 'password');
            }
            $current = $this->field($request, 'current', 257);
            $new = $this->field($request, 'new', 257);
            $error = match (true) {
                !$this->auth->verify($admin, $current) => 'Поточний пароль неправильний.',
                !hash_equals($new, $this->field($request, 'repeat', 257)) => 'Нові паролі не збігаються.',
                hash_equals($new, $current) => 'Новий пароль має відрізнятися від поточного.',
                default => AdminAuth::passwordProblem($new, $admin),
            };
            if ($error !== null) {
                $this->audit->event('admin_password_fail', $admin, $this->remote($request));
                return $this->page($response, 'admin_password', 'password', ['error' => $error], 422);
            }
            $this->auth->setPassword($admin, $new);
            $this->audit->event('admin_password_changed', $admin, $this->remote($request), ['by' => $admin]);
            $this->flash('Пароль змінено.');
            return $this->redirect($response, '/admin');
        });
    }
}
