<?php

declare(strict_types=1);

namespace VpnGw\Admin;

use VpnGw\Auth\Authenticator;
use VpnGw\Config;
use VpnGw\Mail\Notifier;
use VpnGw\RouterOs\RouterOsFactory;

/**
 * "Перевірити" buttons of the admin panel. Uses the SAVED configuration.
 *
 * @phpstan-type Check array{name: string, ok: bool, message: string}
 */
class Diagnostics
{
    /** TEST-NET-1 address: never a real client, safe to look up. */
    private const PROBE_IP = '192.0.2.1';

    public function __construct(
        private readonly Config $cfg,
        private readonly Authenticator $auth,
        private readonly Notifier $notifier,
    ) {
    }

    /** @return list<Check> */
    public function routers(): array
    {
        $out = [];
        foreach (RouterOsFactory::fromConfig($this->cfg) as $router) {
            try {
                $router->findEntry($this->cfg->get('MT_LIST'), self::PROBE_IP);
                $out[] = ['name' => $router->name(), 'ok' => true, 'message' => 'З’єднання, сертифікат і доступ до address-list «' . $this->cfg->get('MT_LIST') . '» — OK'];
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $prefix = $router->name() . ': ';
                $out[] = ['name' => $router->name(), 'ok' => false, 'message' => str_starts_with($msg, $prefix) ? substr($msg, strlen($prefix)) : $msg];
            }
        }
        return $out;
    }

    /** @return Check */
    public function ldap(string $username, #[\SensitiveParameter] string $password): array
    {
        $r = $this->auth->authenticate($username, $password);
        $message = match ($r->reason) {
            'ok' => 'Вхід успішний, користувач у групі VPN' . ($r->mail !== null ? ", e-mail: {$r->mail}" : ', e-mail в AD не заповнено'),
            'bad_credentials' => 'Контролер домену доступний, але логін або пароль неправильні',
            'not_allowed' => 'Пароль правильний, але обліковий запис вимкнено або він не в групі VPN',
            'expired' => 'Термін дії облікового запису або пароля минув',
            'ldap_unavailable' => 'Жоден контролер домену недоступний або сертифікат не довірений (LDAP_HOSTS, LDAP_CA_FILE)',
            'invalid_input' => 'Введіть логін (без домену) і пароль',
            default => $r->reason,
        };
        return ['name' => $username === '' ? 'AD' : $username, 'ok' => $r->ok, 'message' => $message];
    }

    /** @return Check */
    public function mail(string $to, string $admin): array
    {
        $error = $this->notifier->test($to, $admin);
        return ['name' => $to, 'ok' => $error === null, 'message' => $error ?? 'Лист прийнято SMTP-сервером. Перевірте скриньку.'];
    }
}
