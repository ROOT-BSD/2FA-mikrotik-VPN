<?php

declare(strict_types=1);

/**
 * Helper for the root agent; runs as the application user (vpngw) via runuser, so that
 * only this user ever opens the SQLite database. Not meant to be run by hand.
 *   config               JSON with the settings the agent needs
 *   snapshot             consistent DB copy in DATA_DIR/tmp, prints its path
 *   health               "ok <version>" when config, DB and migrations are fine
 *   audit <event> <json> writes an audit event (backup_ok, update_error, ...)
 */

use VpnGw\Version;

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';
$cfg = $c->config();

switch ($argv[1] ?? '') {
    case 'config':
        $out = ['version' => Version::CURRENT];
        foreach (['DB_PATH', 'LOG_PATH', 'LOG_RETENTION_DAYS', 'BACKUP_ENABLED', 'BACKUP_HOUR', 'BACKUP_KEEP',
            'BACKUP_PASSPHRASE', 'LDAP_CA_FILE', 'MT_CA_FILE', 'SMTP_CA_FILE'] as $k) {
            $out[$k] = $cfg->get($k);
        }
        fwrite(STDOUT, json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        break;
    case 'snapshot':
        $dir = $cfg->get('DATA_DIR') . '/tmp';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            fwrite(STDERR, "Cannot create $dir\n");
            exit(1);
        }
        $path = $dir . '/snapshot-' . bin2hex(random_bytes(8)) . '.sqlite';
        $c->db()->snapshotTo($path);
        fwrite(STDOUT, $path . "\n");
        break;
    case 'health':
        $c->db()->migrate();
        $c->db()->countTotp();
        fwrite(STDOUT, 'ok ' . Version::CURRENT . "\n");
        break;
    case 'audit':
        $event = $argv[2] ?? '';
        $extra = json_decode($argv[3] ?? '{}', true);
        if (!preg_match('/^(backup|update)_[a-z]+$/', $event) || !is_array($extra)) {
            exit(2);
        }
        $c->audit()->event($event, null, null, array_filter($extra, 'is_scalar'));
        break;
    default:
        fwrite(STDERR, "Usage: agent-helper.php config|snapshot|health|audit\n");
        exit(2);
}
