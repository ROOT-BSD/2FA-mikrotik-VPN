<?php

declare(strict_types=1);

/**
 * Administrators of the web panel (<APP_BASE_PATH>/admin). Run as the application user:
 *   sudo -u vpngw php bin/admin.php list
 *   sudo -u vpngw php bin/admin.php create <login>     # asks for the password (twice)
 *   sudo -u vpngw php bin/admin.php passwd <login>     # new password for an existing login
 *   sudo -u vpngw php bin/admin.php delete <login>
 * Options: --random   generate a password and print it (no prompt)
 *          --stdin    read the password from standard input (one line)
 */

use VpnGw\Admin\AdminAuth;

/** @var VpnGw\Container $c */
$c = require __DIR__ . '/bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$flags = array_filter(array_slice($argv, 1), static fn ($a) => str_starts_with($a, '--'));
[$cmd, $login] = [$args[0] ?? '', strtolower($args[1] ?? '')];
$auth = $c->adminAuth();

$readPassword = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return rtrim((string) $line, "\r\n");
};

$obtain = static function () use ($flags, $readPassword, $login): array {
    if (in_array('--random', $flags, true)) {
        return [AdminAuth::generatePassword(), true];
    }
    if (in_array('--stdin', $flags, true)) {
        return [rtrim((string) fgets(STDIN), "\r\n"), false];
    }
    $p1 = $readPassword("Пароль для $login (мінімум " . AdminAuth::MIN_PASSWORD . ' символів): ');
    $p2 = $readPassword('Повторіть пароль: ');
    if (!hash_equals($p1, $p2)) {
        fwrite(STDERR, "Паролі не збігаються\n");
        exit(1);
    }
    return [$p1, false];
};

switch ($cmd) {
    case 'list':
        foreach ($c->db()->listAdmins() as $a) {
            fwrite(STDOUT, sprintf("%-20s створено %s, останній вхід %s\n", $a['username'], $a['created_at'], $a['last_login_at'] ?? '—'));
        }
        break;
    case 'create':
    case 'passwd':
        if (!AdminAuth::isValidUsername($login)) {
            fwrite(STDERR, "Логін: 2–32 символи a-z 0-9 . _ -\n");
            exit(2);
        }
        if ($cmd === 'create' && $auth->exists($login)) {
            fwrite(STDERR, "Адміністратор $login уже існує (змінити пароль: passwd $login)\n");
            exit(3);
        }
        if ($cmd === 'passwd' && !$auth->exists($login)) {
            fwrite(STDERR, "Адміністратора $login немає\n");
            exit(3);
        }
        [$password, $generated] = $obtain();
        try {
            $auth->setPassword($login, $password);
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        $c->rateLimiter()->clear("admin-user:$login"); // a new password also lifts the login lockout
        $c->audit()->event($cmd === 'create' ? 'admin_created' : 'admin_password_changed', $login, null, ['by' => 'cli']);
        fwrite(STDOUT, $generated ? "$login\t$password\n" : "Готово: $login\n");
        break;
    case 'delete':
        if (!$c->db()->deleteAdmin($login)) {
            fwrite(STDERR, "Адміністратора $login немає\n");
            exit(3);
        }
        $c->audit()->event('admin_deleted', $login, null, ['by' => 'cli']);
        fwrite(STDOUT, "Видалено: $login\n");
        break;
    default:
        fwrite(STDERR, "Usage: admin.php list | create <login> | passwd <login> | delete <login>  [--random|--stdin]\n");
        exit(2);
}
