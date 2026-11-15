<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var list<array{name: string, size: int, mtime: int}> $backups
 * @var list<array<string, string>> $jobs
 * @var bool $enabled
 * @var int $hour
 * @var int $keep
 * @var bool $hasPassphrase
 * @var bool $busy
 * @var string $backupDir
 */
$states = ['queued' => 'у черзі', 'running' => 'виконується', 'ok' => 'успішно', 'failed' => 'помилка'];
$kinds = ['auto' => 'за розкладом', 'manual' => 'вручну', 'preupdate' => 'перед оновленням'];
?>
<h1>Резервні копії</h1>
<?php if (!$hasPassphrase): ?>
    <div class="alert alert-warning">Не задано пароль шифрування — копії не створюються. <a href="<?= $e($base) ?>/admin/settings/backup">Задати</a></div>
<?php endif ?>
<p class="muted">
    <?= $enabled ? 'Щодня о ' . $e(sprintf('%02d:00', $hour)) : 'Автоматичне копіювання вимкнено' ?> · зберігається <?= $e($keep) ?> останніх копій ·
    <a href="<?= $e($base) ?>/admin/settings/backup">змінити</a>
</p>
<form method="post" action="<?= $e($base) ?>/admin/backups">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn-inline"<?= $busy || !$hasPassphrase ? ' disabled' : '' ?>><?= $busy ? 'Копіювання виконується…' : 'Створити копію зараз' ?></button>
</form>

<section class="box">
    <h2>Збережені копії</h2>
<?php if ($backups === []): ?>
    <p class="muted">Копій ще немає.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Файл</th><th>Тип</th><th>Створено</th><th>Розмір</th><th></th></tr></thead>
        <tbody>
<?php foreach ($backups as $b): preg_match('/-(auto|manual|preupdate)\.vgbk$/', $b['name'], $m); ?>
            <tr>
                <td><code><?= $e($b['name']) ?></code></td>
                <td><?= $e($kinds[$m[1] ?? ''] ?? '') ?></td>
                <td class="nowrap"><?= $e(date('Y-m-d H:i', $b['mtime'])) ?></td>
                <td class="nowrap"><?= $e(number_format($b['size'] / 1024, 0, ',', ' ')) ?> КБ</td>
                <td><a href="<?= $e($base) ?>/admin/backups/<?= $e($b['name']) ?>" class="btn-small">Завантажити</a></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
    <p class="help">Копія містить: базу даних (секрети TOTP зашифровані APP_KEY), <code>.env</code> з ключами, сертифікати TLS і CA, конфігурацію Apache, PHP-FPM, nftables. Зберігайте завантажені копії поза сервером.</p>
</section>

<section class="box">
    <h2>Відновлення</h2>
    <p class="small">Відновлення виконується на сервері від root (так безпечніше, ніж через веб):</p>
    <pre class="code">sudo php /srv/vpn-gw/bin/restore.php <?= $e($backupDir) ?>/&lt;файл&gt;.vgbk          # показати вміст
sudo php /srv/vpn-gw/bin/restore.php <?= $e($backupDir) ?>/&lt;файл&gt;.vgbk --apply  # відновити</pre>
    <p class="help">Копію, завантажену на інший комп’ютер, спершу скопіюйте на сервер (WinSCP / scp). Пароль копії запитується під час відновлення.</p>
</section>

<?php if ($jobs !== []): ?>
<section class="box">
    <h2>Журнал копіювання</h2>
    <table class="table">
        <thead><tr><th>Запуск</th><th>Хто</th><th>Стан</th><th>Результат</th></tr></thead>
        <tbody>
<?php foreach ($jobs as $j): ?>
            <tr>
                <td class="nowrap"><a href="<?= $e($base) ?>/admin/jobs/<?= $e($j['id']) ?>"><?= $e($j['started'] ?? $j['created']) ?></a></td>
                <td><?= $e($j['by']) ?></td>
                <td><span class="badge <?= $e($j['state']) ?>"><?= $e($states[$j['state']] ?? $j['state']) ?></span></td>
                <td class="details"><?= $e($j['message'] ?? '') ?></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</section>
<?php endif ?>
