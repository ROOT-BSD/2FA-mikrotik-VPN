<?php

declare(strict_types=1);

namespace VpnGw\Tests\Settings;

use PHPUnit\Framework\TestCase;
use VpnGw\ConfigException;
use VpnGw\RouterOs\RouterOsFactory;
use VpnGw\Settings\SettingsSchema;
use VpnGw\Tests\Support\TestConfig;

/** 0.5: each router may have its own login and password. */
final class RouterCredentialsTest extends TestCase
{
    private const MIXED = '[{"name":"core","host":"10.0.10.1","version":7,"port":8443,"user":"portal_core"},'
        . '{"name":"branch","host":"10.0.20.1","version":6,"port":8729},'
        . '{"name":"old","host":"10.0.30.1","version":6,"port":22,"transport":"ssh","user":"portal_old"}]';

    private const HOSTKEY = '{"old":"SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"}';

    public function testOwnCredentialsAndFallbackToCommon(): void
    {
        $c = TestConfig::make(['ROUTERS' => self::MIXED, 'MT_SSH_HOSTKEYS' => self::HOSTKEY,
            'ROUTER_PASSWORDS' => '{"core":"core-pass","old":"old-pass"}']);
        [$core, $branch, $old] = $c->routers();
        self::assertSame(['user' => 'portal_core', 'password' => 'core-pass', 'own_user' => true, 'own_password' => true], $c->routerCredentials($core));
        self::assertSame(['user' => 'vpn_portal', 'password' => 'secret', 'own_user' => false, 'own_password' => false], $c->routerCredentials($branch));
        self::assertSame('portal_old', $c->routerCredentials($old)['user']);
        self::assertSame('old-pass', $c->routerCredentials($old)['password']);
        self::assertSame(['core', 'old'], $c->routersWithOwnPassword());
    }

    public function testFactoryUsesPerRouterCredentials(): void
    {
        $c = TestConfig::make(['ROUTERS' => self::MIXED, 'MT_SSH_HOSTKEYS' => self::HOSTKEY,
            'ROUTER_PASSWORDS' => '{"core":"core-pass","old":"old-pass"}']);
        $clients = RouterOsFactory::fromConfig($c);
        self::assertCount(3, $clients);
        $read = static function (object $o, string $prop): mixed {
            $p = new \ReflectionProperty($o, $prop);
            return $p->getValue($o);
        };
        self::assertSame('portal_core', $read($clients[0], 'user'));
        self::assertSame('core-pass', $read($clients[0], 'pass'));
        self::assertSame('vpn_portal', $read($clients[1], 'user'));
        self::assertSame('secret', $read($clients[1], 'pass'));
        self::assertSame('portal_old', $read($clients[2], 'user'));
        self::assertSame('old-pass', $read($clients[2], 'password'));
    }

    public function testCommonUserNotNeededWhenEveryRouterHasItsOwn(): void
    {
        $routers = '[{"name":"core","host":"10.0.10.1","version":7,"port":8443,"user":"a"},'
            . '{"name":"old","host":"10.0.30.1","version":6,"port":22,"transport":"ssh","user":"b"}]';
        $c = TestConfig::make(['ROUTERS' => $routers, 'MT_USER' => '', 'MT_PASS' => '', 'ROUTER_PASSWORDS' => '{"old":"p"}']);
        self::assertSame('b', $c->routerCredentials($c->routers()[1])['user']);
    }

    public function testOldRoutersWithoutUserStillWork(): void
    {
        $c = TestConfig::make();
        self::assertSame('', $c->routers()[0]['user']);
        self::assertSame('vpn_portal', $c->routerCredentials($c->routers()[0])['user']);
        self::assertSame([], $c->routersWithOwnPassword());
    }

    public function testPasswordsForUnknownRoutersAreIgnored(): void
    {
        $c = TestConfig::make(['ROUTER_PASSWORDS' => '{"gone":"x","core":""}']);
        self::assertSame([], $c->routersWithOwnPassword());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalid(): array
    {
        $ssh = '[{"name":"old","host":"10.0.30.1","version":6,"port":22,"transport":"ssh","user":"portal_old"}]';
        return [
            'user with quote' => [['ROUTERS' => '[{"name":"a","host":"h","version":7,"port":443,"user":"x\""}]']],
            'user with space' => [['ROUTERS' => '[{"name":"a","host":"h","version":7,"port":443,"user":"a b"}]']],
            'user not string' => [['ROUTERS' => '[{"name":"a","host":"h","version":7,"port":443,"user":5}]']],
            'api router without any user' => [['MT_USER' => '', 'ROUTERS' => '[{"name":"a","host":"h","version":7,"port":443}]']],
            'ssh own user but no password or key' => [['ROUTERS' => $ssh]],
            'passwords as list' => [['ROUTER_PASSWORDS' => '["x"]']],
            'passwords bad json' => [['ROUTER_PASSWORDS' => 'core:x']],
            'password not string' => [['ROUTER_PASSWORDS' => '{"core":1}']],
            'password too long' => [['ROUTER_PASSWORDS' => '{"core":"' . str_repeat('a', 257) . '"}']],
        ];
    }

    /** @param array<string, string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $override): void
    {
        $this->expectException(ConfigException::class);
        TestConfig::make($override);
    }

    public function testRouterPasswordsAreWriteOnlySecret(): void
    {
        self::assertTrue(SettingsSchema::isSecret('ROUTER_PASSWORDS'));
        self::assertTrue(SettingsSchema::isEncrypted('ROUTER_PASSWORDS'));
        self::assertContains('ROUTER_PASSWORDS', SettingsSchema::editableKeys());
    }

    /** 0.7: own address-list per router. */
    public function testRouterListFallsBackToMtList(): void
    {
        $c = TestConfig::make(['ROUTERS' => '[{"name":"core","host":"10.0.10.1","version":7,"port":8443,"list":"VPN_core"},{"name":"branch","host":"10.0.20.1","version":6,"port":8729}]']);
        self::assertSame(['core' => 'VPN_core', 'branch' => 'Allowed_VPN_Users'], $c->routerLists());
        self::assertSame('', $c->routers()[1]['list']);
    }

    public function testRouterListIsValidated(): void
    {
        foreach (['bad list', 'x"y', str_repeat('a', 65)] as $bad) {
            try {
                TestConfig::make(['ROUTERS' => '[{"name":"core","host":"10.0.10.1","version":7,"port":8443,"list":' . json_encode($bad) . '}]']);
                self::fail("accepted $bad");
            } catch (ConfigException) {
                self::assertTrue(true);
            }
        }
    }
}
