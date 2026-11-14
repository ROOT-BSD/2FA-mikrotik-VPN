<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

use VpnGw\Config;

final class RouterOsFactory
{
    /** @return list<RouterOsClient> */
    public static function fromConfig(Config $cfg): array
    {
        $clients = [];
        foreach ($cfg->routers() as $r) {
            $args = [$r['name'], $r['host'], $r['port'], $cfg->get('MT_USER'), $cfg->get('MT_PASS'), $cfg->get('MT_CA_FILE')];
            $clients[] = $r['version'] === 7 ? new RestClientV7(...$args) : new ApiSslClientV6(...$args);
        }
        return $clients;
    }
}
