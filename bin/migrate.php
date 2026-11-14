<?php

declare(strict_types=1);

/** Creates / updates the SQLite schema. Usage: sudo -u vpngw php bin/migrate.php */

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';
$c->db()->migrate();
fwrite(STDOUT, 'VPN-GW ' . VpnGw\Version::CURRENT . " — schema is up to date: {$c->config()->get('DB_PATH')}\n");
