<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $base
 * @var list<array{time: string, level: string, event: string, category: string, user: string, ip: string, details: string}> $entries
 * @var array{q: string, level: string, category: string} $filter
 * @var array<string, string> $categories
 * @var string $logPath
 * @var string $syslog
 * @var string $remote
 */
?>
<h3 class="mb-2">Журнал аудиту</h3>
<p class="text-muted small">Файл <code><?= $e($logPath) ?></code><?= $syslog !== '' ? ' · syslog ' . $e($syslog) : '' ?><?= $remote !== '' ? ' · SIEM ' . $e($remote) : '' ?></p>
<form method="get" action="<?= $e($base) ?>/admin/logs" class="card p-3 shadow-sm mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label for="q" class="form-label small mb-1">Пошук</label>
            <input id="q" name="q" value="<?= $e($filter['q']) ?>" class="form-control form-control-sm" placeholder="логін, IP, подія" maxlength="100">
        </div>
        <div class="col-md-2">
            <label for="level" class="form-label small mb-1">Рівень</label>
            <select id="level" name="level" class="form-select form-select-sm">
                <option value="">— усі —</option>
                <option value="info"<?= $filter['level'] === 'info' ? ' selected' : '' ?>>info</option>
                <option value="warning"<?= $filter['level'] === 'warning' ? ' selected' : '' ?>>warning</option>
            </select>
        </div>
        <div class="col-md-3">
            <label for="category" class="form-label small mb-1">Категорія</label>
            <select id="category" name="category" class="form-select form-select-sm">
                <option value="">— усі —</option>
<?php foreach ($categories as $k => $label): ?>
                <option value="<?= $e($k) ?>"<?= $filter['category'] === $k ? ' selected' : '' ?>><?= $e($k) ?></option>
<?php endforeach ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Показати</button></div>
    </div>
</form>
<?php if ($entries === []): ?>
    <p class="text-muted">Записів не знайдено.</p>
<?php else: ?>
<p class="text-muted small">Останні <?= $e(count($entries)) ?> записів, новіші зверху.</p>
<div class="table-responsive">
<table class="table table-bordered table-sm table-compact-rows bg-white log">
    <thead><tr><th>Час</th><th>Подія</th><th>Користувач</th><th>IP</th><th>Деталі</th></tr></thead>
    <tbody>
<?php foreach ($entries as $r): ?>
        <tr class="lvl-<?= $e($r['level']) ?><?= $r['level'] === 'warning' ? ' table-warning' : '' ?>">
            <td class="text-nowrap"><?= $e($dt($r['time'])) ?></td>
            <td class="text-nowrap event"><?= $e($r['event']) ?></td>
            <td><?= $e($r['user']) ?></td>
            <td><?php if ($r['ip'] !== ''): ?><code><?= $e($r['ip']) ?></code><?php endif ?></td>
            <td class="details text-break"><?= $e($r['details']) ?></td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
