<?php
/**
 * @var callable(mixed): string $e
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
?>
<h1>Огляд</h1>
<?php foreach ($warnings as $w): ?>
    <div class="alert alert-warning"><?= $e($w) ?></div>
<?php endforeach ?>
<div class="tiles">
    <div class="tile"><span class="tile-num"><?= $e($active) ?></span><span class="tile-label">активних доступів зараз</span></div>
    <div class="tile"><span class="tile-num"><?= $e($enrolled) ?></span><span class="tile-label">зареєстрованих автентифікаторів</span></div>
    <div class="tile"><span class="tile-num"><?= $e(count($routers)) ?></span><span class="tile-label">маршрутизаторів MikroTik</span></div>
    <div class="tile"><span class="tile-num"><?= $e($version) ?></span><span class="tile-label">версія VPN-GW</span></div>
</div>

<div class="grid2">
    <section class="box">
        <h2>Підключення</h2>
        <dl class="facts">
            <dt>MikroTik</dt><dd><?php foreach ($routers as $r): ?><?= $e($r['name']) ?> — <?= $e($r['host']) ?>:<?= $e($r['port']) ?> (v<?= $e($r['version']) ?>, <?= $r['transport'] === 'ssh' ? 'SSH' : 'API' ?>)<br><?php endforeach ?></dd>
            <dt>AD</dt><dd><?= $e(implode(', ', $ldapHosts)) ?></dd>
            <dt>Пошта</dt><dd><?= $smtp === '' ? 'вимкнено' : $e($smtp) ?></dd>
            <dt>Журнал</dt><dd><?= $e($logPath) ?></dd>
            <dt>Остання копія</dt><dd><?= $lastBackup === null ? 'немає' : $e($lastBackup['name']) ?></dd>
        </dl>
    </section>
    <section class="box">
        <h2>Тільки в .env</h2>
        <p class="muted small">Ці параметри впливають на безпеку доступу до панелі, тому змінюються лише на сервері.</p>
        <dl class="facts">
<?php foreach ($readonly as $k => $v): ?>
            <dt><code><?= $e($k) ?></code></dt><dd><?= $e($v) ?></dd>
<?php endforeach ?>
        </dl>
    </section>
</div>

<section class="box">
    <h2>Останні видачі доступу</h2>
<?php if ($grants === []): ?>
    <p class="muted">Ще не було.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Користувач</th><th>IP</th><th>Відкрито</th><th>До</th><th>Стан</th></tr></thead>
        <tbody>
<?php foreach ($grants as $g): ?>
            <tr>
                <td><?= $e($g['username']) ?></td><td><code><?= $e($g['ip']) ?></code></td>
                <td><?= $e($g['granted_at']) ?></td><td><?= $e($g['expires_at']) ?></td>
                <td><?php if ($g['revoked_at'] !== null): ?><span class="badge">закрито</span><?php elseif ($g['expires_at'] > $now): ?><span class="badge ok">активний</span><?php else: ?><span class="badge">завершено</span><?php endif ?></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
</section>

<?php if ($jobs !== []): ?>
<section class="box">
    <h2>Останні завдання</h2>
    <table class="table">
        <thead><tr><th>Завдання</th><th>Створено</th><th>Хто</th><th>Стан</th></tr></thead>
        <tbody>
<?php foreach ($jobs as $j): ?>
            <tr>
                <td><a href="<?= $e($base) ?>/admin/jobs/<?= $e($j['id']) ?>"><?= $j['type'] === 'update' ? 'Оновлення' : 'Резервна копія' ?></a></td>
                <td><?= $e($j['created']) ?></td><td><?= $e($j['by']) ?></td>
                <td><span class="badge <?= $e($j['state']) ?>"><?= $e($states[$j['state']] ?? $j['state']) ?></span></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</section>
<?php endif ?>
