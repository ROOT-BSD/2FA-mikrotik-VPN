<?php

declare(strict_types=1);

/**
 * Shared bootstrap for CLI tools: loads config and returns the service container.
 * CLI tools must run as the "vpngw" user (owner of the DB and lock directory).
 */

use VpnGw\Config;
use VpnGw\Container;
use VpnGw\Http\Session;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$cliSession = [];
return new Container(Config::load(dirname(__DIR__)), new Session($cliSession));
