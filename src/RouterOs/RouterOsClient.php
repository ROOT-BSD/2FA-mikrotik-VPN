<?php

declare(strict_types=1);

namespace VpnGw\RouterOs;

/**
 * Operations the portal needs on a MikroTik router. Parameters are always passed as
 * separate fields (REST JSON / API words), never concatenated into CLI commands.
 * All methods throw RouterOsException on any failure.
 */
interface RouterOsClient
{
    /** Router name from configuration (for logs). */
    public function name(): string;

    /** @return string|null the entry's ".id" or null if absent */
    public function findEntry(string $list, string $ip): ?string;

    public function addEntry(string $list, string $ip, string $timeout, string $comment): void;

    public function updateEntry(string $id, string $timeout, string $comment): void;

    public function removeEntry(string $id): void;

    /**
     * Terminates the user's active VPN session: PPP (L2TP) sessions with this name and
     * IPsec active peers from this IP. Removing the address-list entry alone does not
     * cut an already established tunnel.
     */
    public function dropSessions(string $username, string $ip): void;
}
