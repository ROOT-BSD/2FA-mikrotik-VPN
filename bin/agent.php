<?php

declare(strict_types=1);

/**
 * VPN-GW privileged agent — runs as ROOT, started by systemd only:
 *   vpn-gw-agent.path   (a job appeared in /var/lib/vpn-gw/spool)
 *   vpn-gw-agent.timer  (hourly: scheduled backup, logrotate settings)
 * Manual use:
 *   sudo php /srv/vpn-gw/bin/agent.php run        # process queue + periodic checks
 *   sudo php /srv/vpn-gw/bin/agent.php backup     # create a backup now
 */

use VpnGw\Agent\Agent;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (posix_getuid() !== 0) {
    fwrite(STDERR, "agent.php must run as root (it is started by systemd: vpn-gw-agent.service)\n");
    exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';
umask(0077);

// An update replaces the code on disk while this process runs: load every class now.
foreach ([Agent::class, VpnGw\Agent\BackupArchive::class, VpnGw\Agent\Proc::class, VpnGw\Agent\ReleaseInspector::class, VpnGw\Admin\Jobs::class] as $class) {
    class_exists($class);
}

$cmd = $argv[1] ?? 'run';
try {
    $agent = Agent::fromInstallConf();
    match ($cmd) {
        'run' => $agent->run(),
        'backup' => fwrite(STDOUT, 'Created ' . $agent->backup('manual', 'cli') . "\n"),
        default => throw new InvalidArgumentException('Usage: agent.php run|backup'),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'agent: ' . $e->getMessage() . "\n");
    exit(1);
}
