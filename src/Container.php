<?php

declare(strict_types=1);

namespace VpnGw;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use VpnGw\Auth\Authenticator;
use VpnGw\Auth\EmailOtp;
use VpnGw\Auth\LdapAuthenticator;
use VpnGw\Auth\TotpService;
use VpnGw\Http\EnrollController;
use VpnGw\Http\LoginController;
use VpnGw\Http\MfaController;
use VpnGw\Http\ResultController;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Mail\Notifier;
use VpnGw\RouterOs\AccessGranter;
use VpnGw\Security\Crypto;
use VpnGw\Security\RateLimiter;
use VpnGw\Storage\Database;
use VpnGw\Support\SystemClock;

/**
 * Hand-written service container: every service is created lazily, once.
 * Tests may pass ready-made instances in $overrides (keyed by method name).
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances;

    /** @param array<string, object> $overrides */
    public function __construct(
        private readonly Config $cfg,
        private readonly Session $session,
        array $overrides = [],
    ) {
        $this->instances = $overrides;
    }

    public function config(): Config
    {
        return $this->cfg;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function clock(): ClockInterface
    {
        return $this->get(__FUNCTION__, static fn () => new SystemClock(), ClockInterface::class);
    }

    public function db(): Database
    {
        return $this->get(__FUNCTION__, fn () => new Database($this->cfg->get('DB_PATH')), Database::class);
    }

    public function crypto(): Crypto
    {
        return $this->get(__FUNCTION__, fn () => new Crypto($this->cfg->key('APP_KEY'), $this->cfg->key('APP_HMAC_KEY')), Crypto::class);
    }

    public function audit(): AuditLogger
    {
        return $this->get(__FUNCTION__, fn () => AuditLogger::create($this->cfg->get('LOG_PATH')), AuditLogger::class);
    }

    public function authenticator(): Authenticator
    {
        return $this->get(__FUNCTION__, fn () => new LdapAuthenticator(
            $this->cfg->list('LDAP_HOSTS'),
            $this->cfg->get('LDAP_UPN_SUFFIX'),
            $this->cfg->get('LDAP_BASE_DN'),
            $this->cfg->get('LDAP_VPN_GROUP_DN'),
            $this->cfg->get('LDAP_CA_FILE'),
        ), Authenticator::class);
    }

    public function totp(): TotpService
    {
        return $this->get(__FUNCTION__, fn () => new TotpService($this->db(), $this->crypto(), $this->clock()), TotpService::class);
    }

    public function emailOtp(): EmailOtp
    {
        return $this->get(__FUNCTION__, fn () => new EmailOtp($this->session, $this->crypto(), $this->clock()), EmailOtp::class);
    }

    public function notifier(): Notifier
    {
        return $this->get(__FUNCTION__, fn () => new Notifier($this->cfg, $this->audit()), Notifier::class);
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->get(__FUNCTION__, fn () => new RateLimiter($this->db(), $this->clock()), RateLimiter::class);
    }

    public function granter(): AccessGranter
    {
        return $this->get(__FUNCTION__, fn () => AccessGranter::fromConfig($this->cfg, $this->db(), $this->audit(), $this->clock()), AccessGranter::class);
    }

    public function responseFactory(): ResponseFactoryInterface
    {
        return $this->get(__FUNCTION__, static fn () => new ResponseFactory(), ResponseFactoryInterface::class);
    }

    public function view(): View
    {
        return $this->get(__FUNCTION__, fn () => new View(dirname(__DIR__) . '/templates', $this->cfg->basePath(), $this->session), View::class);
    }

    public function loginController(): LoginController
    {
        return $this->get(__FUNCTION__, fn () => new LoginController(
            $this->cfg, $this->session, $this->view(), $this->clock(),
            $this->authenticator(), $this->totp(), $this->rateLimiter(), $this->audit(),
        ), LoginController::class);
    }

    public function mfaController(): MfaController
    {
        return $this->get(__FUNCTION__, fn () => new MfaController(
            $this->cfg, $this->session, $this->view(), $this->clock(),
            $this->totp(), $this->emailOtp(), $this->notifier(), $this->rateLimiter(), $this->granter(), $this->audit(),
        ), MfaController::class);
    }

    public function resultController(): ResultController
    {
        return $this->get(__FUNCTION__, fn () => new ResultController(
            $this->cfg, $this->session, $this->view(), $this->clock(), $this->granter(), $this->audit(),
        ), ResultController::class);
    }

    public function enrollController(): EnrollController
    {
        return $this->get(__FUNCTION__, fn () => new EnrollController(
            $this->cfg, $this->session, $this->view(), $this->clock(),
            $this->authenticator(), $this->totp(), $this->rateLimiter(), $this->audit(),
        ), EnrollController::class);
    }

    /**
     * @template T of object
     * @param callable(): T $factory
     * @param class-string<T> $type
     * @return T
     */
    private function get(string $id, callable $factory, string $type): object
    {
        $obj = $this->instances[$id] ??= $factory();
        if (!$obj instanceof $type) {
            throw new \LogicException("Service $id is not a $type");
        }
        return $obj;
    }
}
