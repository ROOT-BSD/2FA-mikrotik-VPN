<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
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
$stateBadge = ['queued' => 'bg-secondary', 'running' => 'bg-primary', 'ok' => 'bg-success', 'failed' => 'bg-danger'];
$kinds = ['auto' => 'за розкладом', 'manual' => 'вручну', 'preupdate' => 'перед оновленням'];
?>
<h3 class="mb-2">Резервні копії</h3>
<?php if (!$hasPassphrase): ?>
    <div class="alert alert-warning">Не задано пароль шифрування — копії не створюються. <a href="<?= $e($base) ?>/admin/settings/backup" class="alert-link">Задати</a></div>
<?php endif ?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <span class="text-muted"><?= $enabled ? 'Щодня о ' . $e(sprintf('%02d:00', $hour)) : 'Автоматичне копіювання вимкнено' ?> · зберігається <?= $e($keep) ?> останніх копій</span>
    <form method="post" action="<?= $e($base) ?>/admin/backups" class="m-0">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-primary btn-sm"<?= $busy || !$hasPassphrase ? ' disabled' : '' ?>><?= $busy ? 'Копіювання виконується…' : 'Створити копію зараз' ?></button>
    </form>
</div>

<div class="card mb-4">
    <div class="card-header">Збережені копії</div>
    <div class="card-body">
<?php if ($backups === []): ?>
        <p class="text-muted mb-2">Копій ще немає.</p>
<?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Файл</th><th>Тип</th><th>Створено</th><th>Розмір</th><th></th></tr></thead>
            <tbody>
<?php foreach ($backups as $b): preg_match('/-(auto|manual|preupdate)\.vgbk$/', $b['name'], $m); ?>
                <tr>
                    <td><code><?= $e($b['name']) ?></code></td>
                    <td><?= $e($kinds[$m[1] ?? ''] ?? '') ?></td>
                    <td class="text-nowrap"><?= $e($dt($b['mtime'])) ?></td>
                    <td class="text-nowrap"><?= $e(number_format($b['size'] / 1024, 0, ',', ' ')) ?> КБ</td>
                    <td class="text-end"><a href="<?= $e($base) ?>/admin/backups/<?= $e($b['name']) ?>" class="btn btn-outline-primary btn-sm">Завантажити</a></td>
                </tr>
<?php endforeach ?>
            </tbody>
        </table>
        </div>
<?php endif ?>
        <p class="form-text mb-0">Копія містить: базу даних (секрети TOTP зашифровані APP_KEY), <code>.env</code> з ключами, сертифікати TLS і CA, конфігурацію Apache, PHP-FPM, nftables. Зберігайте завантажені копії поза сервером.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Відновлення</div>
    <div class="card-body">
        <p class="small">Відновлення виконується на сервері від root (так безпечніше, ніж через веб):</p>
        <pre class="bg-light border rounded p-2 small">sudo php /srv/vpn-gw/bin/restore.php <?= $e($backupDir) ?>/&lt;файл&gt;.vgbk          # показати вміст
sudo php /srv/vpn-gw/bin/restore.php <?= $e($backupDir) ?>/&lt;файл&gt;.vgbk --apply  # відновити</pre>
        <p class="form-text mb-0">Копію, завантажену на інший комп’ютер, спершу скопіюйте на сервер (WinSCP / scp). Пароль копії запитується під час відновлення.</p>
    </div>
</div>

<?php if ($jobs !== []): ?>
<h5>Журнал копіювання</h5>
<div class="table-responsive">
    <table class="table table-bordered table-sm bg-white">
        <thead><tr><th>Запуск</th><th>Хто</th><th>Стан</th><th>Результат</th></tr></thead>
        <tbody>
<?php foreach ($jobs as $j): ?>
            <tr>
                <td class="text-nowrap"><a href="<?= $e($base) ?>/admin/jobs/<?= $e($j['id']) ?>"><?= $e($dt($j['started'] ?? $j['created'])) ?></a></td>
                <td><?= $e($j['by']) ?></td>
                <td><span class="badge <?= $e($stateBadge[$j['state']] ?? 'bg-secondary') ?>"><?= $e($states[$j['state']] ?? $j['state']) ?></span></td>
                <td class="details text-break"><?= $e($j['message'] ?? '') ?></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
