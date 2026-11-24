<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

use VpnGw\Config;

final class RouterOsFactory
{
    /**
     * @param bool $trustOnFirstUse only for the admin panel check: accept and report an
     *                              unknown SSH host key instead of refusing
     * @return list<RouterOsClient>
     */
    public static function fromConfig(Config $cfg, bool $trustOnFirstUse = false): array
    {
        $clients = [];
        $pinned = $cfg->sshHostKeys();
        foreach ($cfg->activeRouters() as $r) {
            $c = $cfg->routerCredentials($r);
            if ($r['transport'] === 'ssh') {
                $clients[] = SshClient::create($r['name'], $r['host'], $r['port'], $c['user'], $c['password'],
                    $cfg->get('MT_SSH_KEY'), $cfg->get('MT_SSH_KEY_PASS'), $pinned[$r['name']] ?? null, $trustOnFirstUse);
                continue;
            }
            $args = [$r['name'], $r['host'], $r['port'], $c['user'], $c['password'], $cfg->get('MT_CA_FILE')];
            $clients[] = $r['version'] === 7 ? new RestClientV7(...$args) : new ApiSslClientV6(...$args);
        }
        return $clients;
    }
}
