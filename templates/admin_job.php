<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var array<string, string> $job
 * @var string $log
 * @var bool $refresh
 */
$states = ['queued' => 'у черзі — очікує агента', 'running' => 'виконується', 'ok' => 'успішно', 'failed' => 'помилка'];
$back = $job['type'] === 'update' ? ['/admin/update', 'До оновлень'] : ['/admin/backups', 'До резервних копій'];
?>
<h1><?= $job['type'] === 'update' ? 'Оновлення' . (isset($job['version']) ? ' до ' . $e($job['version']) : '') : 'Резервне копіювання' ?></h1>
<div class="alert alert-<?= $e(['ok' => 'success', 'failed' => 'error'][$job['state']] ?? 'info') ?>">
    <strong><?= $e($states[$job['state']] ?? $job['state']) ?></strong><?= isset($job['message']) ? ' — ' . $e($job['message']) : '' ?>
</div>
<dl class="facts">
    <dt>Завдання</dt><dd><code><?= $e($job['id']) ?></code></dd>
    <dt>Ініціатор</dt><dd><?= $e($job['by']) ?></dd>
    <dt>Створено</dt><dd><?= $e($job['created']) ?></dd>
<?php if (isset($job['started'])): ?>
    <dt>Почато</dt><dd><?= $e($job['started']) ?></dd>
<?php endif ?>
<?php if (isset($job['finished'])): ?>
    <dt>Завершено</dt><dd><?= $e($job['finished']) ?></dd>
<?php endif ?>
<?php if (isset($job['file']) && $job['type'] === 'backup'): ?>
    <dt>Файл</dt><dd><a href="<?= $e($base) ?>/admin/backups/<?= $e($job['file']) ?>"><?= $e($job['file']) ?></a></dd>
<?php endif ?>
</dl>
<?php if ($refresh): ?>
    <p class="muted small">Сторінка оновлюється автоматично кожні 5 секунд.</p>
<?php endif ?>
<?php if ($job['state'] === 'queued'): ?>
    <p class="help">Якщо завдання довго в черзі, перевірте на сервері: <code>systemctl status vpn-gw-agent.path vpn-gw-agent.service</code></p>
<?php endif ?>
<?php if ($log !== ''): ?>
<h2>Журнал виконання</h2>
<pre class="code log-output"><?= $e($log) ?></pre>
<?php endif ?>
<p><a href="<?= $e($base . $back[0]) ?>"><?= $e($back[1]) ?></a></p>
