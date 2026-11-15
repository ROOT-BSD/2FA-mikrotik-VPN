<?php

declare(strict_types=1);

/**
 * Settings saved in the admin panel (override .env). Run as the application user:
 *   sudo -u vpngw php bin/settings.php list          # effective values and their source
 *   sudo -u vpngw php bin/settings.php reset KEY     # drop the panel value, .env applies again
 *   sudo -u vpngw php bin/settings.php effective     # KEY=value lines for install.sh (no secrets)
 *   sudo -u vpngw php bin/settings.php init-backup-passphrase   # generate one if none is set
 * Use "reset" if a value saved in the panel prevents the portal from starting.
 */

use VpnGw\Admin\AdminAuth;
use VpnGw\Config;
use VpnGw\ConfigException;
use VpnGw\Settings\SettingsSchema;
use VpnGw\Security\Crypto;
use VpnGw\Settings\SettingsStore;
use VpnGw\Storage\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';

$dir = dirname(__DIR__);
$cmd = $argv[1] ?? 'list';
if ($cmd === 'reset') {
    // Works even when the merged configuration is invalid: only .env is needed here.
    $key = $argv[2] ?? '';
    if (!in_array($key, SettingsSchema::editableKeys(), true)) {
        fwrite(STDERR, "Unknown or non-editable key: $key\n");
        exit(2);
    }
    $env = Dotenv\Dotenv::createArrayBacked($dir)->load();
    $db = new Database((string) ($env['DB_PATH'] ?? ''));
    fwrite(STDOUT, $db->deleteSetting($key) ? "$key: значення з панелі видалено, діє .env\n" : "$key: у панелі не змінювався\n");
    exit(0);
}
try {
    $cfg = Config::load($dir);
} catch (ConfigException $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\nFix .env or run: settings.php reset <KEY>\n");
    exit(1);
}
if ($cmd === 'effective') {
    foreach (['MT_PASS', 'LDAP_HOSTS', 'LDAP_UPN_SUFFIX', 'LDAP_BASE_DN', 'LDAP_VPN_GROUP_DN', 'LDAP_CA_FILE', 'MT_CA_FILE',
        'ROUTERS', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_CA_FILE', 'APP_BASE_PATH', 'DB_PATH', 'LOG_PATH'] as $key) {
        // MT_PASS is only reported as set / empty
        $v = $key === 'MT_PASS' ? ($cfg->get($key) === '' ? '' : 'set') : $cfg->get($key);
        fwrite(STDOUT, $key . '=' . str_replace(["\r", "\n"], ' ', $v) . "\n");
    }
    exit(0);
}
if ($cmd === 'init-backup-passphrase') {
    if ($cfg->get('BACKUP_PASSPHRASE') !== '') {
        fwrite(STDOUT, "present\n");
        exit(0);
    }
    $db = new Database($cfg->get('DB_PATH'));
    $db->migrate();
    $pass = AdminAuth::generatePassword(24);
    (new SettingsStore($db, new Crypto($cfg->key('APP_KEY'), $cfg->key('APP_HMAC_KEY'))))
        ->save(['BACKUP_PASSPHRASE' => $pass], 'install.sh', date('Y-m-d H:i:s'));
    fwrite(STDOUT, "generated:$pass\n");
    exit(0);
}
foreach (SettingsSchema::editableKeys() as $key) {
    $value = SettingsSchema::isSecret($key) ? ($cfg->get($key) === '' ? '(порожньо)' : '••••••') : $cfg->get($key);
    fwrite(STDOUT, sprintf("%-22s %-8s %s\n", $key, $cfg->source($key), $value));
}
