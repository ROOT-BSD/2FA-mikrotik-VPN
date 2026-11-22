<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $base
 * @var array<string, string> $job
 * @var string $log
 * @var bool $refresh
 */
$states = ['queued' => 'у черзі — очікує агента', 'running' => 'виконується', 'ok' => 'успішно', 'failed' => 'помилка'];
$back = $job['type'] === 'update' ? ['/admin/update', 'Оновлення'] : ['/admin/backups', 'Резервні копії'];
?>
<div class="mb-3"><a href="<?= $e($base . $back[0]) ?>" class="text-decoration-none">&larr; <?= $e($back[1]) ?></a></div>
<h3 class="mb-3"><?= $job['type'] === 'update' ? 'Оновлення' . (isset($job['version']) ? ' до ' . $e($job['version']) : '') : 'Резервне копіювання' ?></h3>
<div class="alert alert-<?= $e(['ok' => 'success', 'failed' => 'danger'][$job['state']] ?? 'info') ?>">
    <strong><?= $e($states[$job['state']] ?? $job['state']) ?></strong><?= isset($job['message']) ? ' — ' . $e($job['message']) : '' ?>
</div>
<div class="card mb-4 form-card-md">
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr><th class="label-col">Завдання</th><td><code><?= $e($job['id']) ?></code></td></tr>
            <tr><th>Ініціатор</th><td><?= $e($job['by']) ?></td></tr>
            <tr><th>Створено</th><td><?= $e($dt($job['created'])) ?></td></tr>
<?php if (isset($job['started'])): ?>
            <tr><th>Почато</th><td><?= $e($dt($job['started'])) ?></td></tr>
<?php endif ?>
<?php if (isset($job['finished'])): ?>
            <tr><th>Завершено</th><td><?= $e($dt($job['finished'])) ?></td></tr>
<?php endif ?>
<?php if (isset($job['file']) && $job['type'] === 'backup'): ?>
            <tr><th>Файл</th><td><a href="<?= $e($base) ?>/admin/backups/<?= $e($job['file']) ?>"><?= $e($job['file']) ?></a></td></tr>
<?php endif ?>
        </table>
    </div>
</div>
<?php if ($refresh): ?>
    <p class="text-muted small">Сторінка оновлюється автоматично кожні 5 секунд.</p>
<?php endif ?>
<?php if ($job['state'] === 'queued'): ?>
    <p class="form-text">Якщо завдання довго в черзі, перевірте на сервері: <code>systemctl status vpn-gw-agent.path vpn-gw-agent.service</code></p>
<?php endif ?>
<?php if ($log !== ''): ?>
<div class="d-flex flex-wrap align-items-baseline gap-3 mb-2">
    <h5 class="mb-0">Журнал виконання</h5>
    <span class="small log-legend"><span class="log-ok">[ OK ]</span> успішно · <span class="log-warn">[WARN]</span> попередження · <span class="log-fail">[FAIL]</span> помилка</span>
</div>
<pre class="bg-dark text-light rounded p-3 small log-output"><?php
// install.sh writes "[ OK ]", "[WARN]", "[FAIL]" / "[FATAL]" at the start of a line (no colour codes when
// not on a terminal); each line is escaped and only gets a class by its marker.
foreach (preg_split('/\r?\n/', (string) rtrim((string) preg_replace('/\e\[[0-9;]*m/', '', $log))) ?: [] as $line):
    $cls = match (true) {
        preg_match('/\[\s*OK\s*\]/', $line) === 1 => 'log-ok',
        str_contains($line, '[WARN]') => 'log-warn',
        str_contains($line, '[FAIL]'), str_contains($line, '[FATAL]') => 'log-fail',
        default => '',
    };
    echo $cls === '' ? $e($line) : '<span class="' . $cls . '">' . $e($line) . '</span>', "\n";
endforeach ?></pre>
<?php endif ?>
