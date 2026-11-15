<?php
/**
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
    'dashboard' => ['/admin', 'Огляд'],
    'mikrotik' => ['/admin/settings/mikrotik', 'MikroTik'],
    'mail' => ['/admin/settings/mail', 'Пошта'],
    'ad' => ['/admin/settings/ad', 'Active Directory'],
    'logs' => ['/admin/logs', 'Журнали'],
    'backups' => ['/admin/backups', 'Резервні копії'],
    'update' => ['/admin/update', 'Оновлення'],
];
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
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css">
</head>
<body class="admin">
<header class="topbar">
    <a class="brand" href="<?= $e($base) ?>/admin"><span class="logo" aria-hidden="true">V</span><span class="brand-name">VPN-GW</span><span class="brand-sub">адміністрування</span></a>
    <div class="who">
        <a href="<?= $e($base) ?>/admin/password" class="<?= $nav === 'password' ? 'active' : '' ?>"><?= $e($admin) ?></a>
        <form method="post" action="<?= $e($base) ?>/admin/logout" class="inline">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn-small ghost">Вийти</button>
        </form>
    </div>
</header>
<div class="shell">
    <nav class="sidenav" aria-label="Розділи">
<?php foreach ($items as $key => [$href, $label]): ?>
        <a href="<?= $e($base . $href) ?>" class="<?= $nav === $key ? 'active' : '' ?>"><?= $e($label) ?></a>
<?php if ($key === 'logs'): ?>
        <a href="<?= $e($base) ?>/admin/settings/logs" class="sub <?= $nav === 'logs_settings' ? 'active' : '' ?>">Налаштування журналів</a>
<?php elseif ($key === 'backups'): ?>
        <a href="<?= $e($base) ?>/admin/settings/backup" class="sub <?= $nav === 'backup' ? 'active' : '' ?>">Розклад і пароль</a>
<?php endif ?>
<?php endforeach ?>
    </nav>
    <main class="panel">
<?php if ($flash !== null): ?>
        <div class="alert alert-<?= $e(in_array($flash['kind'], ['success', 'warning', 'error', 'info'], true) ? $flash['kind'] : 'info') ?>" role="status"><?= $e($flash['text']) ?></div>
<?php endif ?>
<?= $content ?>
    </main>
</div>
</body>
</html>
