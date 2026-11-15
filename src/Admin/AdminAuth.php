<?php

declare(strict_types=1);

namespace VpnGw\Admin;

use Psr\Clock\ClockInterface;
use VpnGw\Storage\Database;

/**
 * Local administrator accounts of the admin panel (independent of AD, so the panel
 * still works when AD settings are wrong). Passwords are hashed with Argon2id.
 */
final class AdminAuth
{
    public const MIN_PASSWORD = 12;

    /** Hash of a random string: verified when the login does not exist, so timing does not reveal it. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$RTk1WkliQ2ovbkRLWmNNNg$yyUg3052xq6yTEELmDd0zllD6XWVDE/+ukdGomF4rEA';

    public function __construct(
        private readonly Database $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/', $username) === 1;
    }

    public static function hash(#[\SensitiveParameter] string $password): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : password_hash($password, PASSWORD_DEFAULT);
    }

    /** Ukrainian message describing why the password is not acceptable, or null. */
    public static function passwordProblem(#[\SensitiveParameter] string $password, string $username): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'Пароль має містити щонайменше ' . self::MIN_PASSWORD . ' символів.';
        }
        if (strlen($password) > 256) {
            return 'Пароль задовгий (максимум 256 байт).';
        }
        if (stripos($password, $username) !== false) {
            return 'Пароль не може містити логін.';
        }
        if (count(array_unique(mb_str_split($password))) < 5) {
            return 'Пароль занадто простий.';
        }
        return null;
    }

    public function verify(string $username, #[\SensitiveParameter] string $password): bool
    {
        if (!self::isValidUsername($username) || $password === '' || strlen($password) > 256) {
            password_verify($password, self::DUMMY_HASH);
            return false;
        }
        $row = $this->db->findAdmin($username);
        if ($row === null) {
            password_verify($password, self::DUMMY_HASH);
            return false;
        }
        if (!password_verify($password, $row['password_hash'])) {
            return false;
        }
        if (password_needs_rehash($row['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->db->upsertAdmin($username, self::hash($password), $this->now());
        }
        return true;
    }

    public function exists(string $username): bool
    {
        return $this->db->findAdmin($username) !== null;
    }

    /** Creates the account or replaces its password. */
    public function setPassword(string $username, #[\SensitiveParameter] string $password): void
    {
        if (!self::isValidUsername($username)) {
            throw new \InvalidArgumentException('Invalid administrator login');
        }
        $problem = self::passwordProblem($password, $username);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $this->db->upsertAdmin($username, self::hash($password), $this->now());
    }

    public function recordLogin(string $username, string $ip): void
    {
        $this->db->recordAdminLogin($username, $ip, $this->now());
    }

    /** Random password for the installer: 20 characters without look-alikes. */
    public static function generatePassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
