<?php
/**
 * Admin panel layout in the style of the ITSM System: Bootstrap 5, dark top menu,
 * light background, container. No JavaScript (CSP script-src 'none'): the menu is always
 * expanded and wraps on narrow screens.
 *
 * @var callable(mixed): string $e
 * @var string $title
 * @var string $base
 * @var string $csrf
 * @var string $content
 * @var string $nav
 * @var string|null $admin
 * @var array{text: string, kind: string}|null $flash
 * @var bool|null $refresh
 */
$refresh ??= false;
$items = [
    'dashboard' => ['/admin', 'Огляд', ['dashboard']],
    'authenticators' => ['/admin/authenticators', 'Автентифікатори', ['authenticators']],
    'mikrotik' => ['/admin/settings/mikrotik', 'MikroTik', ['mikrotik']],
    'mail' => ['/admin/settings/mail', 'Пошта', ['mail']],
    'ad' => ['/admin/settings/ad', 'Active Directory', ['ad']],
    'logs' => ['/admin/logs', 'Журнали', ['logs', 'logs_settings']],
    'backups' => ['/admin/backups', 'Резервні копії', ['backups', 'backup']],
    'update' => ['/admin/update', 'Оновлення', ['update']],
];
$tabs = [
    'logs' => [['logs', '/admin/logs', 'Журнал аудиту'], ['logs_settings', '/admin/settings/logs', 'Налаштування журналів']],
    'backups' => [['backups', '/admin/backups', 'Резервні копії'], ['backup', '/admin/settings/backup', 'Розклад і пароль']],
];
$group = null;
foreach ($items as $key => $it) {
    if (in_array($nav, $it[2], true)) {
        $group = $key;
    }
}
$flashKind = ['success' => 'success', 'warning' => 'warning', 'error' => 'danger'][$flash['kind'] ?? ''] ?? 'info';
?><!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
<?php if ($refresh): ?>
    <meta http-equiv="refresh" content="5">
<?php endif ?>
    <title><?= $e($title) ?></title>
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css">
</head>
<body class="bg-light admin d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand navbar-dark bg-dark">
    <div class="container flex-wrap">
        <a class="navbar-brand" href="<?= $e($base) ?>/admin">VPN-GW</a>
        <ul class="navbar-nav me-auto flex-wrap">
<?php foreach ($items as $key => [$href, $label]): ?>
            <li class="nav-item"><a class="nav-link<?= $group === $key ? ' active' : '' ?>" href="<?= $e($base . $href) ?>"<?= $group === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a></li>
<?php endforeach ?>
        </ul>
        <div class="d-flex align-items-center">
            <a href="<?= $e($base) ?>/admin/password" class="text-light text-decoration-none small me-3<?= $nav === 'password' ? ' fw-semibold' : '' ?>" title="Змінити пароль"><?= $e($admin) ?></a>
            <form method="post" action="<?= $e($base) ?>/admin/logout" class="m-0">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <button type="submit" class="btn btn-outline-light btn-sm">Вийти</button>
            </form>
        </div>
    </div>
</nav>

<main class="container py-4 flex-grow-1">
<?php if ($group !== null && isset($tabs[$group])): ?>
    <ul class="nav nav-tabs mb-4">
<?php foreach ($tabs[$group] as [$key, $href, $label]): ?>
        <li class="nav-item"><a class="nav-link<?= $nav === $key ? ' active' : '' ?>" href="<?= $e($base . $href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a></li>
<?php endforeach ?>
    </ul>
<?php endif ?>
<?php if ($flash !== null): ?>
    <div class="alert alert-<?= $e($flashKind) ?>" role="status"><?= $e($flash['text']) ?></div>
<?php endif ?>
<?= $content ?>
</main>

<footer class="text-center text-muted small py-3">VPN-GW v<?= $e(\VpnGw\Version::CURRENT) ?></footer>
</body>
</html>
