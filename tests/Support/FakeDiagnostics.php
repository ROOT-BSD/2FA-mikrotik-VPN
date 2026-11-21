<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

use VpnGw\Admin\Diagnostics;

final class FakeDiagnostics extends Diagnostics
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function routers(string $admin = 'admin'): array
    {
        $this->calls[] = 'routers';
        return [['name' => 'core', 'ok' => true, 'message' => 'OK'], ['name' => 'branch', 'ok' => false, 'message' => 'Connection timed out']];
    }

    public function addressLists(): array
    {
        $this->calls[] = 'lists';
        return [
            'lists' => [
                'Allowed_VPN_Users' => ['core' => ['entries' => 3, 'rules' => 2]],
                'blacklist' => ['core' => ['entries' => 120, 'rules' => 1]],
                'VPN_2FA' => ['core' => ['entries' => 0, 'rules' => 1]],
            ],
            'errors' => [['name' => 'branch', 'ok' => false, 'message' => 'Connection timed out']],
            'routers' => ['core'],
        ];
    }

    public function ldap(string $username, string $password): array
    {
        $this->calls[] = "ldap:$username";
        return ['name' => $username, 'ok' => $password === 'right', 'message' => $password === 'right' ? 'Вхід успішний' : 'Контролер домену доступний, але логін або пароль неправильні'];
    }

    public function mail(string $to, string $admin): array
    {
        $this->calls[] = "mail:$to";
        return ['name' => $to, 'ok' => true, 'message' => 'Лист прийнято'];
    }
}
