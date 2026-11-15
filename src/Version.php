<?php

declare(strict_types=1);

namespace VpnGw;

/**
 * Application version. The single source of truth is the VERSION file in the project
 * root; this constant must match it (enforced by VersionTest).
 *
 * The version is written to the audit log and shown by CLI tools, but is deliberately
 * NOT exposed on public pages (no fingerprinting); only the internal admin panel shows it.
 */
final class Version
{
    public const CURRENT = '0.2';
}
