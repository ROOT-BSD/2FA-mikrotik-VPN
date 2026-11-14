<?php

declare(strict_types=1);

namespace VpnGw\Tests\Support;

use VpnGw\Config;

final class TestConfig
{
    /** @return array<string, string> */
    public static function values(): array
    {
        return [
            'APP_BASE_PATH' => '/k7f3x',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat("\x01", 32)),
            'APP_HMAC_KEY' => 'base64:' . base64_encode(str_repeat("\x02", 32)),
            'TRUSTED_PROXIES' => '127.0.0.1',
            'INTERNAL_NETWORKS' => '10.0.0.0/8',
            'LDAP_HOSTS' => 'ldaps://dc01.company.local:636',
            'LDAP_UPN_SUFFIX' => '@company.local',
            'LDAP_BASE_DN' => 'DC=company,DC=local',
            'LDAP_VPN_GROUP_DN' => 'CN=VPN-Users,OU=Groups,DC=company,DC=local',
            'LDAP_CA_FILE' => '/etc/ssl/company-ca.pem',
            'ROUTERS' => '[{"name":"core","host":"10.0.10.1","version":7,"port":8443},{"name":"branch","host":"10.0.20.1","version":6,"port":8729}]',
            'MT_USER' => 'vpn_portal',
            'MT_PASS' => 'secret',
            'MT_CA_FILE' => '/etc/ssl/company-ca.pem',
            'MT_LIST' => 'Allowed_VPN_Users',
            'MT_TIMEOUT' => '10:00:00',
            'DB_PATH' => ':memory:',
            'LOCK_DIR' => sys_get_temp_dir() . '/vpngw-test-locks',
            'LOG_PATH' => 'php://memory',
            'EMAIL_OTP_ENABLED' => 'false',
        ];
    }

    /** @param array<string, string> $override */
    public static function make(array $override = []): Config
    {
        return Config::fromArray($override + self::values());
    }
}
