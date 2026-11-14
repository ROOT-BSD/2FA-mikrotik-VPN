<?php

declare(strict_types=1);

namespace VpnGw\Http;

/**
 * Wrapper around $_SESSION (passed by reference) so controllers stay testable.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array &$data)
    {
        $this->data = &$data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function str(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->data[$key] ?? null;
        return is_int($v) ? $v : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string ...$keys): void
    {
        foreach ($keys as $k) {
            unset($this->data[$k]);
        }
    }

    /**
     * Replaces all data (keeps the same underlying array).
     *
     * @param array<string, mixed> $values
     */
    public function replace(array $values): void
    {
        foreach (array_keys($this->data) as $k) {
            unset($this->data[$k]);
        }
        foreach ($values as $k => $v) {
            $this->data[$k] = $v;
        }
    }

    /** New session id after a privilege change (login, MFA). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Clears data and destroys the server-side session. */
    public function destroy(): void
    {
        $this->replace([]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        $t = $this->str('csrf');
        if ($t === null) {
            $t = bin2hex(random_bytes(32));
            $this->data['csrf'] = $t;
        }
        return $t;
    }
}
