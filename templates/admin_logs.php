<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var list<array{time: string, level: string, event: string, category: string, user: string, ip: string, details: string}> $entries
 * @var array{q: string, level: string, category: string} $filter
 * @var array<string, string> $categories
 * @var string $logPath
 * @var string $syslog
 * @var string $remote
 */
?>
<h1>Журнал аудиту</h1>
<p class="muted">Файл <code><?= $e($logPath) ?></code><?= $syslog !== '' ? ' · syslog ' . $e($syslog) : '' ?><?= $remote !== '' ? ' · SIEM ' . $e($remote) : '' ?> · <a href="<?= $e($base) ?>/admin/settings/logs">налаштування</a></p>
<form method="get" action="<?= $e($base) ?>/admin/logs" class="filters">
    <input name="q" value="<?= $e($filter['q']) ?>" placeholder="пошук: логін, IP, подія" maxlength="100" aria-label="Пошук">
    <select name="level" aria-label="Рівень">
        <option value="">усі рівні</option>
        <option value="info"<?= $filter['level'] === 'info' ? ' selected' : '' ?>>info</option>
        <option value="warning"<?= $filter['level'] === 'warning' ? ' selected' : '' ?>>warning</option>
    </select>
    <select name="category" aria-label="Категорія">
        <option value="">усі категорії</option>
<?php foreach ($categories as $k => $label): ?>
        <option value="<?= $e($k) ?>"<?= $filter['category'] === $k ? ' selected' : '' ?>><?= $e($k) ?></option>
<?php endforeach ?>
    </select>
    <button type="submit" class="btn-inline">Показати</button>
</form>
<?php if ($entries === []): ?>
    <p class="muted">Записів не знайдено.</p>
<?php else: ?>
<p class="muted small">Останні <?= $e(count($entries)) ?> записів, новіші зверху.</p>
<div class="table-wrap">
<table class="table log">
    <thead><tr><th>Час</th><th>Подія</th><th>Користувач</th><th>IP</th><th>Деталі</th></tr></thead>
    <tbody>
<?php foreach ($entries as $r): ?>
        <tr class="lvl-<?= $e($r['level']) ?>">
            <td class="nowrap"><?= $e($r['time']) ?></td>
            <td><span class="badge cat-<?= $e($r['category']) ?>"><?= $e($r['event']) ?></span></td>
            <td><?= $e($r['user']) ?></td>
            <td><?php if ($r['ip'] !== ''): ?><code><?= $e($r['ip']) ?></code><?php endif ?></td>
            <td class="details"><?= $e($r['details']) ?></td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
