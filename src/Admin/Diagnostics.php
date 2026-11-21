<?php

declare(strict_types=1);

namespace VpnGw\Admin;

use VpnGw\Auth\Authenticator;
use VpnGw\Config;
use VpnGw\Mail\Notifier;
use VpnGw\RouterOs\RouterOsFactory;
use VpnGw\RouterOs\SshClient;
use VpnGw\Settings\SettingsStore;

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
        private readonly ?SettingsStore $store = null,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /**
     * Connects to every router with the saved settings and reads the address-list.
     * SSH routers without a pinned host key are accepted here (trust on first use, the
     * administrator sees the fingerprint) and the key is saved for the portal.
     *
     * @return list<Check>
     */
    public function routers(string $admin = 'admin'): array
    {
        $out = [];
        $pinned = $this->cfg->sshHostKeys();
        $newKeys = [];
        $lists = $this->cfg->routerLists();
        foreach (RouterOsFactory::fromConfig($this->cfg, true) as $router) {
            try {
                $list = $lists[$router->name()] ?? $this->cfg->get('MT_LIST');
                $router->findEntry($list, self::PROBE_IP);
                $msg = 'З’єднання, автентифікація і доступ до address-list «' . $list . '» — OK';
                if ($router instanceof SshClient) {
                    $fp = (string) $router->seenFingerprint();
                    $router->close();
                    if (!isset($pinned[$router->name()])) {
                        $newKeys[$router->name()] = $fp;
                        $msg .= ". SSH-ключ маршрутизатора збережено: $fp. Звірте з адмінського ПК: ssh-keyscan -p <порт> <адреса> | ssh-keygen -lf - (має бути серед рядків)";
                    } else {
                        $msg .= ". SSH-ключ збігається зі збереженим ($fp)";
                    }
                }
                $out[] = ['name' => $router->name(), 'ok' => true, 'message' => $msg];
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $prefix = $router->name() . ': ';
                $out[] = ['name' => $router->name(), 'ok' => false, 'message' => str_starts_with($msg, $prefix) ? substr($msg, strlen($prefix)) : $msg];
            }
        }
        if ($newKeys !== [] && $this->store !== null) {
            $this->store->save(['MT_SSH_HOSTKEYS' => json_encode($newKeys + $pinned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
                $admin, ($this->now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        }
        return $out;
    }

    /**
     * Address lists of every router (saved settings), for choosing MT_LIST in the panel.
     * SSH routers must already have a confirmed host key («Перевірити MikroTik»).
     *
     * @return array{lists: array<string, array<string, array{entries: int, rules: int}>>, errors: list<Check>, routers: list<string>}
     *         lists: name => router => counts
     */
    public function addressLists(): array
    {
        $lists = [];
        $errors = [];
        $ok = [];
        foreach (RouterOsFactory::fromConfig($this->cfg) as $router) {
            try {
                foreach ($router->addressLists() as $name => $info) {
                    $lists[$name][$router->name()] = $info;
                }
                $ok[] = $router->name();
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $prefix = $router->name() . ': ';
                $errors[] = ['name' => $router->name(), 'ok' => false, 'message' => str_starts_with($msg, $prefix) ? substr($msg, strlen($prefix)) : $msg];
            } finally {
                if ($router instanceof SshClient) {
                    $router->close();
                }
            }
        }
        uksort($lists, 'strnatcasecmp');
        return ['lists' => $lists, 'errors' => $errors, 'routers' => $ok];
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
            'ldap_unavailable' => 'Жоден контролер домену недоступний або не вдалося встановити захищене з’єднання (режим, LDAP_HOSTS, LDAP_CA_FILE)',
            'invalid_input' => 'Введіть логін (user або user@' . $this->cfg->ldapDomain() . ') і пароль',
            'group_not_found' => 'Пароль правильний, але групу VPN «' . (string) $this->cfg->ldapGroupName() . '» не знайдено в домені ' . $this->cfg->ldapDomain(),
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
