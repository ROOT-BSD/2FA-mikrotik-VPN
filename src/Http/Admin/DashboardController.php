<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Admin\Jobs;
use VpnGw\Config;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Storage\Database;
use VpnGw\Version;

final class DashboardController extends AdminController
{
    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        AuditLogger $audit,
        private readonly Database $db,
        private readonly Jobs $jobs,
    ) {
        parent::__construct($cfg, $session, $view, $clock, $audit);
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function () use ($response): Response {
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $warnings = [];
            if ($this->cfg->get('BACKUP_PASSPHRASE') === '') {
                $warnings[] = 'Не задано пароль шифрування резервних копій — копії не створюються.';
            }
            $ldapMode = $this->cfg->get('LDAP_SECURITY');
            if ($ldapMode === 'plain') {
                $warnings[] = 'AD: підключення без шифрування — паролі користувачів передаються мережею відкритим текстом.';
            } elseif (!$this->cfg->ldapVerifiesCertificate()) {
                $warnings[] = 'AD: сертифікат контролера домену не перевіряється — немає захисту від підміни сервера.';
            }
            $unconfirmed = array_filter($this->cfg->routers(), fn ($r) => $r['transport'] === 'ssh' && !isset($this->cfg->sshHostKeys()[$r['name']]));
            if ($unconfirmed !== []) {
                $warnings[] = 'SSH-ключ не підтверджено для: ' . implode(', ', array_column($unconfirmed, 'name')) . ' — MikroTik → «Перевірити MikroTik».';
            }
            if ($this->cfg->get('SMTP_HOST') === '') {
                $warnings[] = 'Пошту вимкнено: користувачі не отримують сповіщень про відкриття доступу.';
            }
            $backups = $this->jobs->backups();
            if ($backups !== [] && $this->clock->now()->getTimestamp() - $backups[0]['mtime'] > 2 * 86400) {
                $warnings[] = 'Остання резервна копія старша за 2 доби.';
            }
            return $this->page($response, 'admin_dashboard', 'dashboard', [
                'version' => Version::CURRENT,
                'enrolled' => $this->db->countTotp(),
                'active' => $this->db->countActiveGrants($now),
                'grants' => $this->db->recentGrants(10),
                'now' => $now,
                'routers' => $this->cfg->routers(),
                'ldapHosts' => $this->cfg->list('LDAP_HOSTS'),
                'smtp' => $this->cfg->get('SMTP_HOST'),
                'logPath' => $this->cfg->get('LOG_PATH'),
                'lastBackup' => $backups[0] ?? null,
                'jobs' => array_slice($this->jobs->list(5), 0, 5),
                'warnings' => $warnings,
                'readonly' => [
                    'APP_BASE_PATH' => $this->cfg->basePath(),
                    'INTERNAL_NETWORKS' => $this->cfg->get('INTERNAL_NETWORKS'),
                    'TRUSTED_PROXIES' => $this->cfg->get('TRUSTED_PROXIES'),
                    'DB_PATH' => $this->cfg->get('DB_PATH'),
                ],
            ]);
        });
    }
}
