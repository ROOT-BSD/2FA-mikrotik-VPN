<?php

declare(strict_types=1);

/** Removes stale rate-limit events and expired lockouts. Run hourly (systemd timer). */

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';
$c->db()->cleanup($c->clock()->now()->getTimestamp());
