<?php

declare(strict_types=1);

namespace VpnGw\Tests\Settings;

use PHPUnit\Framework\TestCase;
use VpnGw\Config;
use VpnGw\ConfigException;
use VpnGw\Security\Crypto;
use VpnGw\Settings\SettingsSchema;
use VpnGw\Settings\SettingsStore;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\TempDir;
use VpnGw\Tests\Support\TestConfig;

final class SettingsTest extends TestCase
{
    private function crypto(string $k = "\x01"): Crypto
    {
        return new Crypto(str_repeat($k, 32), str_repeat("\x02", 32));
    }

    public function testPanelValuesOverrideEnvOnlyForEditableKeys(): void
    {
        $cfg = TestConfig::make();
        $c2 = Config::fromArray(TestConfig::values(), ['MT_LIST' => 'Panel_List', 'APP_BASE_PATH' => '/evil', 'INTERNAL_NETWORKS' => '0.0.0.0/0']);
        self::assertSame('Panel_List', $c2->get('MT_LIST'));
        self::assertSame('/k7f3x', $c2->basePath(), 'base path cannot come from the panel');
        self::assertSame('10.0.0.0/8', $c2->get('INTERNAL_NETWORKS'), 'internal networks cannot come from the panel');
        self::assertSame('panel', $c2->source('MT_LIST'));
        self::assertSame('env', $c2->source('MT_USER'));
        self::assertSame('default', $c2->source('LOG_LEVEL'));
        self::assertSame('Allowed_VPN_Users', $c2->baseValue('MT_LIST'));
        self::assertSame('Allowed_VPN_Users', $cfg->get('MT_LIST'));
    }

    public function testWithPanelValuesValidates(): void
    {
        $this->expectException(ConfigException::class);
        TestConfig::make()->withPanelValues(['MT_TIMEOUT' => 'forever']);
    }

    public function testStoreEncryptsSecretsOnly(): void
    {
        $db = Database::inMemory();
        $store = new SettingsStore($db, $this->crypto());
        $store->save(['MT_PASS' => 'p@ss', 'MT_LIST' => 'L1'], 'admin', '2026-11-15 10:00:00');
        $raw = $db->allSettings();
        self::assertTrue($raw['MT_PASS']['encrypted']);
        self::assertStringNotContainsString('p@ss', $raw['MT_PASS']['value']);
        self::assertFalse($raw['MT_LIST']['encrypted']);
        self::assertSame(['MT_PASS' => 'p@ss', 'MT_LIST' => 'L1'], $store->values());
        self::assertSame('admin', $store->meta()['MT_LIST']['updated_by']);
        self::assertTrue($store->forget('MT_LIST'));
        self::assertSame(['MT_PASS' => 'p@ss'], $store->values());
    }

    public function testStoreRefusesNonEditableKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SettingsStore(Database::inMemory(), $this->crypto()))->save(['APP_KEY' => 'x'], 'a', 'now');
    }

    public function testConfigLoadReadsPanelValuesFromTheDatabase(): void
    {
        $dir = TempDir::make();
        try {
            $values = ['DB_PATH' => "$dir/db.sqlite"] + TestConfig::values();
            $env = '';
            foreach ($values as $k => $v) {
                $env .= $k . "='" . $v . "'\n";
            }
            file_put_contents("$dir/.env", $env);
            $db = new Database("$dir/db.sqlite");
            $db->migrate();
            (new SettingsStore($db, $this->crypto()))->save(['SMTP_HOST' => 'smtp.company.ua', 'SMTP_PASS' => 'mail-secret'], 'admin', 'now');

            $cfg = self::loadIsolated($dir);
            self::assertSame('smtp.company.ua', $cfg->get('SMTP_HOST'));
            self::assertSame('mail-secret', $cfg->get('SMTP_PASS'));
            self::assertSame('panel', $cfg->source('SMTP_HOST'));

            // a changed APP_KEY must not silently drop the stored secrets
            $db->saveSettings(['SMTP_PASS' => ['value' => $this->crypto("\x09")->encrypt('x'), 'encrypted' => true]], 'admin', 'now');
            $this->expectException(ConfigException::class);
            self::loadIsolated($dir);
        } finally {
            TempDir::remove($dir);
        }
    }

    /** Config::load uses the immutable process environment; clear what a previous load put there. */
    private static function loadIsolated(string $dir): Config
    {
        foreach (array_keys(TestConfig::values()) as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        return Config::load($dir);
    }

    public function testEventCategories(): void
    {
        self::assertSame('admin', SettingsSchema::eventCategory('admin_login_fail'));
        self::assertSame('auth', SettingsSchema::eventCategory('mfa_fail'));
        self::assertSame('access', SettingsSchema::eventCategory('mt_add'));
        self::assertSame('enroll', SettingsSchema::eventCategory('totp_reset'));
        self::assertSame('system', SettingsSchema::eventCategory('mt_error'));
        self::assertSame('system', SettingsSchema::eventCategory('mail_error'), 'errors are always logged');
        foreach (SettingsSchema::SECRETS as $s) {
            self::assertContains($s, SettingsSchema::editableKeys());
        }
    }
}
