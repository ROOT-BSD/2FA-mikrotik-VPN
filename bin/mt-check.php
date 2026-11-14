<?php

declare(strict_types=1);

/**
 * Checks MikroTik integration without the browser (use on a TEST router or a test IP).
 *   sudo -u vpngw php bin/mt-check.php find   198.51.100.99
 *   sudo -u vpngw php bin/mt-check.php grant  198.51.100.99
 *   sudo -u vpngw php bin/mt-check.php revoke 198.51.100.99
 * Entries get the comment "User: mt-check via VPN-2FA".
 */

use VpnGw\RouterOs\RouterOsException;
use VpnGw\RouterOs\RouterOsFactory;

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';

$cmd = $argv[1] ?? '';
$ip = $argv[2] ?? '';
if (!in_array($cmd, ['find', 'grant', 'revoke'], true) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    fwrite(STDERR, "Usage: mt-check.php find|grant|revoke <public IPv4>\n");
    exit(2);
}

try {
    switch ($cmd) {
        case 'find':
            foreach (RouterOsFactory::fromConfig($c->config()) as $r) {
                $id = $r->findEntry($c->config()->get('MT_LIST'), $ip);
                fwrite(STDOUT, sprintf("%-12s %s\n", $r->name(), $id === null ? 'absent' : "present (.id=$id)"));
            }
            break;
        case 'grant':
            $until = $c->granter()->grant('mt-check', $ip);
            fwrite(STDOUT, "Granted until {$until->format('Y-m-d H:i:s')}\n");
            break;
        case 'revoke':
            $c->granter()->revoke('mt-check', $ip);
            fwrite(STDOUT, "Revoked\n");
            break;
    }
} catch (RouterOsException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n(see audit log for per-router details)\n");
    exit(1);
}
