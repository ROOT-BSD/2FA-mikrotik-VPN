<?php

declare(strict_types=1);

namespace VpnGw;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use VpnGw\Admin\AdminAuth;
use VpnGw\Admin\Diagnostics;
use VpnGw\Admin\Jobs;
use VpnGw\Admin\LogReader;
use VpnGw\Auth\Authenticator;
use VpnGw\Auth\EmailOtp;
use VpnGw\Auth\LdapAuthenticator;
use VpnGw\Auth\TotpService;
use VpnGw\Http\Admin\AuthController as AdminAuthController;
use VpnGw\Http\Admin\DashboardController;
use VpnGw\Http\Admin\LogsController;
use VpnGw\Http\Admin\MaintenanceController;
use VpnGw\Http\Admin\SettingsController;
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
use VpnGw\Settings\SettingsStore;
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
        return $this->get(__FUNCTION__, fn () => AuditLogger::fromConfig($this->cfg), AuditLogger::class);
    }

    public function authenticator(): Authenticator
    {
        return $this->get(__FUNCTION__, fn () => new LdapAuthenticator(
            $this->cfg->list('LDAP_HOSTS'),
            $this->cfg->ldapUpnSuffix(),
            $this->cfg->ldapBaseDn(),
            $this->cfg->ldapGroupDn() ?? (string) $this->cfg->ldapGroupName(),
            $this->cfg->get('LDAP_CA_FILE'),
            $this->cfg->get('LDAP_SECURITY'),
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

    // ---- admin panel ------------------------------------------------------------

    public function adminAuth(): AdminAuth
    {
        return $this->get(__FUNCTION__, fn () => new AdminAuth($this->db(), $this->clock()), AdminAuth::class);
    }

    public function settingsStore(): SettingsStore
    {
        return $this->get(__FUNCTION__, fn () => new SettingsStore($this->db(), $this->crypto()), SettingsStore::class);
    }

    public function jobs(): Jobs
    {
        return $this->get(__FUNCTION__, fn () => new Jobs(
            $this->cfg->get('DATA_DIR') . '/spool',
            $this->cfg->get('AGENT_DIR') . '/status',
            $this->cfg->get('BACKUP_DIR'),
        ), Jobs::class);
    }

    public function logReader(): LogReader
    {
        return $this->get(__FUNCTION__, fn () => new LogReader($this->cfg->get('LOG_PATH')), LogReader::class);
    }

    public function diagnostics(): Diagnostics
    {
        return $this->get(__FUNCTION__, fn () => new Diagnostics($this->cfg, $this->authenticator(), $this->notifier(), $this->settingsStore(), $this->clock()->now()), Diagnostics::class);
    }

    public function adminAuthController(): AdminAuthController
    {
        return $this->get(__FUNCTION__, fn () => new AdminAuthController(
            $this->cfg, $this->session, $this->view(), $this->clock(), $this->audit(), $this->adminAuth(), $this->rateLimiter(),
        ), AdminAuthController::class);
    }

    public function dashboardController(): DashboardController
    {
        return $this->get(__FUNCTION__, fn () => new DashboardController(
            $this->cfg, $this->session, $this->view(), $this->clock(), $this->audit(), $this->db(), $this->jobs(),
        ), DashboardController::class);
    }

    public function settingsController(): SettingsController
    {
        return $this->get(__FUNCTION__, fn () => new SettingsController(
            $this->cfg, $this->session, $this->view(), $this->clock(), $this->audit(), $this->settingsStore(), $this->diagnostics(),
        ), SettingsController::class);
    }

    public function logsController(): LogsController
    {
        return $this->get(__FUNCTION__, fn () => new LogsController(
            $this->cfg, $this->session, $this->view(), $this->clock(), $this->audit(), $this->logReader(),
        ), LogsController::class);
    }

    public function maintenanceController(): MaintenanceController
    {
        return $this->get(__FUNCTION__, fn () => new MaintenanceController(
            $this->cfg, $this->session, $this->view(), $this->clock(), $this->audit(), $this->jobs(),
        ), MaintenanceController::class);
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
