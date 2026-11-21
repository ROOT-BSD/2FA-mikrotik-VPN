<?php

declare(strict_types=1);

namespace VpnGw\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VpnGw\ConfigException;
use VpnGw\Tests\Support\TestConfig;

final class ConfigTest extends TestCase
{
    public function testValidConfig(): void
    {
        $cfg = TestConfig::make();
        self::assertSame('/k7f3x', $cfg->basePath());
        self::assertSame(36000, $cfg->grantSeconds());
        self::assertSame([5, 900], $cfg->rate('RATE_LOGIN_IP'));
        self::assertCount(2, $cfg->routers());
        self::assertSame(7, $cfg->routers()[0]['version']);
        self::assertFalse($cfg->bool('EMAIL_OTP_ENABLED'));
        self::assertSame(32, strlen($cfg->key('APP_KEY')));
    }

    #[DataProvider('invalid')]
    public function testFailsClosedOnBadValues(string $key, string $value): void
    {
        $this->expectException(ConfigException::class);
        TestConfig::make([$key => $value]);
    }

    /** @return array<string, array{string, string}> */
    public static function invalid(): array
    {
        return [
            'missing key' => ['MT_USER', ''],
            'missing domain' => ['LDAP_UPN_SUFFIX', ''],
            'bad upn suffix' => ['LDAP_UPN_SUFFIX', 'company.local'],
            'smtp port text' => ['SMTP_PORT', 'smtp'],
            'smtp port range' => ['SMTP_PORT', '70000'],
            'smtp from not email' => ['SMTP_FROM', 'vpn-gw'],
            'smtp host spaces' => ['SMTP_HOST', 'mail server'],
            'bool garbage' => ['EMAIL_OTP_ENABLED', 'maybe'],
            'log level' => ['LOG_LEVEL', 'debug'],
            'log facility' => ['LOG_SYSLOG_FACILITY', 'auth'],
            'log category' => ['LOG_EVENTS', 'auth,everything'],
            'log remote no port' => ['LOG_REMOTE', 'siem.company.local'],
            'log remote port range' => ['LOG_REMOTE', 'siem:99999'],
            'log relative path' => ['LOG_PATH', 'logs/audit.log'],
            'retention zero' => ['LOG_RETENTION_DAYS', '0'],
            'backup hour 24' => ['BACKUP_HOUR', '24'],
            'backup keep 0' => ['BACKUP_KEEP', '0'],
            'short passphrase' => ['BACKUP_PASSPHRASE', 'short'],
            'contact newline' => ['IT_CONTACT', "ІТ\nBcc: x"],
            'short app key' => ['APP_KEY', 'base64:' . base64_encode('short')],
            'raw app key' => ['APP_KEY', str_repeat('a', 32)],
            'base path with slash' => ['APP_BASE_PATH', '/a/b'],
            'base path root' => ['APP_BASE_PATH', '/'],
            'plain ldap' => ['LDAP_HOSTS', 'ldap://dc01:389'],
            'bad cidr' => ['INTERNAL_NETWORKS', '10.0.0.0/40'],
            'bad proxy ip' => ['TRUSTED_PROXIES', 'proxy.local'],
            'bad timeout' => ['MT_TIMEOUT', '10h'],
            'list injection' => ['MT_LIST', 'x comment=y'],
            'routers not json' => ['ROUTERS', 'core:10.0.10.1'],
            'routers empty' => ['ROUTERS', '[]'],
            'router version 5' => ['ROUTERS', '[{"name":"a","host":"h","version":5,"port":1}]'],
            'router port string' => ['ROUTERS', '[{"name":"a","host":"h","version":7,"port":"8443"}]'],
            'bad rate' => ['RATE_LOGIN_IP', '5 per 15m'],
        ];
    }
}
