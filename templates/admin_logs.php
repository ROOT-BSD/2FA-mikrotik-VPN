<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $base
 * @var list<array{time: string, level: string, event: string, category: string, user: string, ip: string, details: string, file?: string}> $entries
 * @var int $allLimit
 * @var array{q: string, level: string, category: string} $filter
 * @var array<string, string> $categories
 * @var string $logPath
 * @var string $syslog
 * @var string $remote
 * @var list<array{name: string, size: int, mtime: int, gz: bool, current: bool}> $files
 * @var string $file  selected rotated file; '' — the current log; '*' — all files
 */
$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' МБ' : ($b >= 1024 ? number_format($b / 1024, 0, ',', ' ') . ' КБ' : $b . ' Б');
$all = $file === '*';
$shown = $all ? dirname($logPath) . '/' . basename($logPath) . '*' : ($file !== '' ? dirname($logPath) . '/' . $file : $logPath);
?>
<h3 class="mb-2">Журнал аудиту</h3>
<p class="text-muted small"><?= $all ? 'Усі файли' : 'Файл' ?> <code class="log-file"><?= $e($shown) ?></code><?php if (!$all): ?> · <a href="<?= $e($base) ?>/admin/logs/download<?= $file !== '' ? '?file=' . $e(rawurlencode($file)) : '' ?>">Завантажити</a><?php endif ?><?= $syslog !== '' ? ' · syslog ' . $e($syslog) : '' ?><?= $remote !== '' ? ' · SIEM ' . $e($remote) : '' ?></p>
<form method="get" action="<?= $e($base) ?>/admin/logs" class="card p-3 shadow-sm mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label for="file" class="form-label small mb-1">Файл журналу</label>
            <select id="file" name="file" class="form-select form-select-sm">
<?php if (count($files) > 1): ?>
                <option value="*"<?= $all ? ' selected' : '' ?>>— усі файли (<?= $e(count($files)) ?>) —</option>
<?php endif ?>
<?php foreach ($files as $f): $sel = $f['current'] ? $file === '' : $file === $f['name']; ?>
                <option value="<?= $f['current'] ? '' : $e($f['name']) ?>"<?= $sel ? ' selected' : '' ?>><?= $e($f['name']) ?> — <?= $f['current'] ? 'поточний' : $e($dt($f['mtime'], false)) ?>, <?= $e($size($f['size'])) ?></option>
<?php endforeach ?>
<?php if ($files === []): ?>
                <option value="">— немає —</option>
<?php endif ?>
            </select>
        </div>
        <div class="col-md-4">
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
        <div class="col-md-2">
            <label for="category" class="form-label small mb-1">Категорія</label>
            <select id="category" name="category" class="form-select form-select-sm">
                <option value="">— усі —</option>
<?php foreach ($categories as $k => $label): ?>
                <option value="<?= $e($k) ?>"<?= $filter['category'] === $k ? ' selected' : '' ?>><?= $e($k) ?></option>
<?php endforeach ?>
            </select>
        </div>
    </div>
    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-sm">Показати</button>
        <button type="submit" name="file" value="*" class="btn btn-outline-primary btn-sm show-all"<?= count($files) < 2 ? ' disabled title="Інших файлів журналу ще немає"' : '' ?>>Показати всі</button>
        <span class="small text-muted align-self-center">«Показати всі» — записи з усіх файлів журналу разом (до <?= $e($allLimit) ?>, новіші зверху), з тим самим пошуком і фільтрами.</span>
    </div>
</form>
<?php if ($entries === []): ?>
    <p class="text-muted">Записів не знайдено.</p>
<?php else: ?>
<p class="text-muted small"><?= $all ? 'Усі файли журналу: о' : ($file !== '' ? 'Файл ' . $e($file) . ': о' : 'О') ?>станні <?= $e(count($entries)) ?> записів, новіші зверху.</p>
<div class="table-responsive">
<table class="table table-bordered table-sm table-compact-rows bg-white log">
    <thead><tr><th>Час</th><?= $all ? '<th>Файл</th>' : '' ?><th>Подія</th><th>Користувач</th><th>IP</th><th>Деталі</th></tr></thead>
    <tbody>
<?php foreach ($entries as $r): ?>
        <tr class="lvl-<?= $e($r['level']) ?><?= $r['level'] === 'warning' ? ' table-warning' : '' ?>">
            <td class="text-nowrap"><?= $e($dt($r['time'])) ?></td>
<?php if ($all): ?>
            <td class="text-nowrap small text-muted log-src"><?= $e($r['file'] ?? '') ?></td>
<?php endif ?>
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
