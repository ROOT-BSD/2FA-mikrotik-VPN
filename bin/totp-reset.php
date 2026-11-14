<?php

declare(strict_types=1);

/**
 * Removes a user's TOTP binding (lost or replaced phone). Verify the person's identity first!
 *   sudo -u vpngw php bin/totp-reset.php --user ivanenko --by admin.petrenko
 *   sudo -u vpngw php bin/totp-reset.php --count
 */

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';

$opt = getopt('', ['user:', 'by:', 'count']);
if (isset($opt['count'])) {
    fwrite(STDOUT, 'VPN-GW ' . VpnGw\Version::CURRENT . ' — enrolled users: ' . $c->db()->countTotp() . "\n");
    exit(0);
}
$user = is_string($opt['user'] ?? null) ? strtolower($opt['user']) : '';
$by = is_string($opt['by'] ?? null) ? $opt['by'] : '';
if ($by === '' || !VpnGw\Auth\LdapAuthenticator::isValidUsername($user)) {
    fwrite(STDERR, "Usage: totp-reset.php --user <login> --by <admin>   |   totp-reset.php --count\n");
    exit(2);
}

if ($c->totp()->reset($user)) {
    $c->audit()->event('totp_reset', $user, null, ['by' => $by]);
    fwrite(STDOUT, "TOTP binding removed for $user. The user must enroll again from the office network.\n");
    exit(0);
}
fwrite(STDERR, "No TOTP binding for $user\n");
exit(1);
