<?php

declare(strict_types=1);

namespace VpnGw\Settings;

use VpnGw\Security\Crypto;
use VpnGw\Storage\Database;

/**
 * Settings changed in the admin panel. They override .env for the keys listed in
 * SettingsSchema; secrets are encrypted with APP_KEY (sodium secretbox).
 */
final class SettingsStore
{
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
    ) {
    }

    /**
     * Decrypted overrides for editable keys. A secret that cannot be decrypted
     * (APP_KEY changed) is a hard error: silently falling back could hide it.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $editable = SettingsSchema::editableKeys();
        $out = [];
        foreach ($this->db->allSettings() as $key => $row) {
            if (!in_array($key, $editable, true)) {
                continue;
            }
            $out[$key] = $row['encrypted'] ? $this->crypto->decrypt($row['value']) : $row['value'];
        }
        return $out;
    }

    /** @return array<string, array{updated_at: string, updated_by: string}> */
    public function meta(): array
    {
        $out = [];
        foreach ($this->db->allSettings() as $key => $row) {
            $out[$key] = ['updated_at' => $row['updated_at'], 'updated_by' => $row['updated_by']];
        }
        return $out;
    }

    /** @param array<string, string> $changes */
    public function save(array $changes, string $by, string $at): void
    {
        $rows = [];
        foreach ($changes as $key => $value) {
            if (!in_array($key, SettingsSchema::editableKeys(), true)) {
                throw new \InvalidArgumentException("Setting $key is not editable");
            }
            $secret = SettingsSchema::isSecret($key);
            $rows[$key] = ['value' => $secret ? $this->crypto->encrypt($value) : $value, 'encrypted' => $secret];
        }
        if ($rows !== []) {
            $this->db->saveSettings($rows, $by, $at);
        }
    }

    /** Drops the panel value, so .env applies again. */
    public function forget(string $key): bool
    {
        return $this->db->deleteSetting($key);
    }
}
