<?php

declare(strict_types=1);

/**
 * Lifts a lockout.
 *   sudo -u vpngw php bin/unlock.php --ip 203.0.113.45 --by admin.petrenko
 *   sudo -u vpngw php bin/unlock.php --user ivanenko --by admin.petrenko
 */

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';

$opt = getopt('', ['ip:', 'user:', 'by:']);
$by = is_string($opt['by'] ?? null) ? $opt['by'] : '';
$ip = is_string($opt['ip'] ?? null) ? $opt['ip'] : null;
$user = is_string($opt['user'] ?? null) ? strtolower($opt['user']) : null;

if ($by === '' || ($ip === null) === ($user === null)) {
    fwrite(STDERR, "Usage: unlock.php (--ip <addr> | --user <login>) --by <admin>\n");
    exit(2);
}
if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
    fwrite(STDERR, "Invalid IP\n");
    exit(2);
}
if ($user !== null && !VpnGw\Auth\LdapAuthenticator::isValidUsername($user)) {
    fwrite(STDERR, "Invalid login\n");
    exit(2);
}

$key = $ip !== null ? "ip:$ip" : "user:$user";
$c->rateLimiter()->clear($key);
if ($user !== null) {
    $c->rateLimiter()->clear("mail:$user");
}
$c->audit()->event('unlock', $user, $ip, ['by' => $by]);
fwrite(STDOUT, "Unlocked $key\n");
