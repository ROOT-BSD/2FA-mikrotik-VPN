<?php

declare(strict_types=1);

use VpnGw\App;
use VpnGw\Config;
use VpnGw\Container;
use VpnGw\Http\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = Config::load(dirname(__DIR__));
} catch (\Throwable $e) {
    // Fail closed: misconfiguration must never expose a half-working portal.
    error_log('VPN-GW config error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', '900');
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
session_name('__Host-vpngw');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

/** @var array<string, mixed> $_SESSION */
$container = new Container($config, new Session($_SESSION));
App::create($container)->run();
