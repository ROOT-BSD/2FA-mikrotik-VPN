<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $csrf
 * @var string $base
 * @var string $version
 * @var int $enrolled
 * @var int $active
 * @var list<array{username: string, ip: string, granted_at: string, expires_at: string, revoked_at: string|null}> $grants
 * @var string $now
 * @var list<array{name: string, host: string, version: int, port: int, transport: string}> $routers
 * @var list<string> $ldapHosts
 * @var string $smtp
 * @var string $logPath
 * @var array{name: string, size: int, mtime: int}|null $lastBackup
 * @var list<array<string, string>> $jobs
 * @var list<string> $warnings
 * @var array<string, string> $readonly
 */
$states = ['queued' => 'у черзі', 'running' => 'виконується', 'ok' => 'успішно', 'failed' => 'помилка'];
$stateBadge = ['queued' => 'bg-secondary', 'running' => 'bg-primary', 'ok' => 'bg-success', 'failed' => 'bg-danger'];
?>
<h3 class="mb-4">Огляд</h3>
<?php foreach ($warnings as $w): ?>
    <div class="alert alert-warning"><?= $e($w) ?></div>
<?php endforeach ?>
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= $e($active) ?></h2><div class="text-muted">Активних доступів зараз</div></div></div>
    <div class="col-6 col-md-3 mb-3"><a href="<?= $e($base) ?>/admin/authenticators" class="text-decoration-none text-reset"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= $e($enrolled) ?></h2><div class="text-muted">Зареєстрованих автентифікаторів</div></div></a></div>
    <div class="col-6 col-md-3 mb-3"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= $e(count($routers)) ?></h2><div class="text-muted">Маршрутизаторів MikroTik</div></div></div>
    <div class="col-6 col-md-3 mb-3"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= $e($version) ?></h2><div class="text-muted">Версія VPN-GW</div></div></div>
</div>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Підключення</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th class="label-col">MikroTik</th><td><?php foreach ($routers as $r): ?><?= $e($r['name']) ?> — <?= $e($r['host']) ?>:<?= $e($r['port']) ?> (v<?= $e($r['version']) ?>, <?= $r['transport'] === 'ssh' ? 'SSH' : 'API' ?>)<br><?php endforeach ?></td></tr>
                    <tr><th>AD</th><td><?= $e(implode(', ', $ldapHosts)) ?></td></tr>
                    <tr><th>Пошта</th><td><?= $smtp === '' ? '<span class="text-muted">вимкнено</span>' : $e($smtp) ?></td></tr>
                    <tr><th>Журнал</th><td><code><?= $e($logPath) ?></code></td></tr>
                    <tr><th>Остання копія</th><td><?= $lastBackup === null ? '<span class="text-muted">немає</span>' : $e($lastBackup['name']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Тільки в .env</span><span class="badge bg-secondary">на сервері</span></div>
            <div class="card-body">
                <p class="small text-muted">Ці параметри впливають на безпеку доступу до панелі, тому змінюються лише на сервері.</p>
                <table class="table table-sm mb-0">
<?php foreach ($readonly as $k => $v): ?>
                    <tr><th class="label-col"><code><?= $e($k) ?></code></th><td class="text-break"><?= $e($v) ?></td></tr>
<?php endforeach ?>
                </table>
            </div>
        </div>
    </div>
</div>

<h5>Останні видачі доступу</h5>
<p class="small text-muted mb-2">«Відключити» видаляє IP користувача з address-list усіх маршрутизаторів і розриває його VPN-сесію. Щоб знову підключитися, користувач має пройти портал ще раз.</p>
<?php if ($grants === []): ?>
    <p class="text-muted">Ще не було.</p>
<?php else: ?>
<div class="table-responsive mb-4">
    <table class="table table-bordered table-sm bg-white">
        <thead><tr><th>Користувач</th><th>IP</th><th>Відкрито</th><th>До</th><th>Стан</th><th></th></tr></thead>
        <tbody>
<?php foreach ($grants as $g): $isActive = $g['revoked_at'] === null && (int) strtotime($g['expires_at']) > (int) strtotime($now); ?>
            <tr>
                <td><?= $e($g['username']) ?></td><td><code><?= $e($g['ip']) ?></code></td>
                <td class="text-nowrap"><?= $e($dt($g['granted_at'])) ?></td><td class="text-nowrap"><?= $e($dt($g['expires_at'])) ?></td>
                <td><?php if ($g['revoked_at'] !== null): ?><span class="badge bg-secondary" title="<?= $e($dt($g['revoked_at'])) ?>">закрито</span><?php elseif ($isActive): ?><span class="badge bg-success">активний</span><?php else: ?><span class="badge bg-light text-dark border">завершено</span><?php endif ?></td>
                <td class="text-end"><?php if ($isActive): ?>
                    <form method="post" action="<?= $e($base) ?>/admin/grants/revoke" class="m-0">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="username" value="<?= $e($g['username']) ?>">
                        <input type="hidden" name="ip" value="<?= $e($g['ip']) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Видалити IP з address-list усіх маршрутизаторів і розірвати VPN-сесію">Відключити</button>
                    </form>
<?php endif ?></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<?php if ($jobs !== []): ?>
<h5>Останні завдання</h5>
<div class="table-responsive">
    <table class="table table-bordered table-sm bg-white">
        <thead><tr><th>Завдання</th><th>Створено</th><th>Хто</th><th>Стан</th></tr></thead>
        <tbody>
<?php foreach ($jobs as $j): ?>
            <tr>
                <td><a href="<?= $e($base) ?>/admin/jobs/<?= $e($j['id']) ?>"><?= $j['type'] === 'update' ? 'Оновлення' : 'Резервна копія' ?></a></td>
                <td class="text-nowrap"><?= $e($dt($j['created'])) ?></td><td><?= $e($j['by']) ?></td>
                <td><span class="badge <?= $e($stateBadge[$j['state']] ?? 'bg-secondary') ?>"><?= $e($states[$j['state']] ?? $j['state']) ?></span></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
