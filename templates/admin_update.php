<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $base
 * @var string $csrf
 * @var string $version
 * @var list<array<string, string>> $jobs
 * @var bool $hasPassphrase
 * @var bool $busy
 * @var int $maxMb
 * @var string|null $error
 */
$error ??= null;
$states = ['queued' => 'у черзі', 'running' => 'виконується', 'ok' => 'успішно', 'failed' => 'помилка'];
$stateBadge = ['queued' => 'bg-secondary', 'running' => 'bg-primary', 'ok' => 'bg-success', 'failed' => 'bg-danger'];
?>
<h3 class="mb-2">Оновлення</h3>
<p class="text-muted">Встановлена версія: <strong><?= $e($version) ?></strong></p>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<?php if (!$hasPassphrase): ?>
    <div class="alert alert-warning">Перед оновленням автоматично створюється резервна копія — спершу <a href="<?= $e($base) ?>/admin/settings/backup" class="alert-link">задайте пароль шифрування копій</a>.</div>
<?php endif ?>

<div class="row">
    <div class="col-lg-7 mb-4">
        <div class="card h-100">
            <div class="card-header">Завантажити нову версію</div>
            <div class="card-body">
                <ol class="small ps-3">
                    <li class="mb-1">Отримайте архів релізу <code>vpn-gw-X.Y.zip</code> і файл контрольної суми <code>vpn-gw-X.Y.zip.sha256</code>.</li>
                    <li class="mb-1">Завантажте обидва файли нижче. Портал перевірить SHA-256, структуру архіву і версію.</li>
                    <li>Далі автоматично: резервна копія → знімок поточної версії → <code>install.sh</code> → перевірка. Якщо щось піде не так — відкат до попередньої версії.</li>
                </ol>
                <form method="post" action="<?= $e($base) ?>/admin/update" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <div class="mb-3">
                        <label for="archive" class="form-label">Архів релізу (.zip, до <?= $e($maxMb) ?> МБ)</label>
                        <input id="archive" name="archive" type="file" accept=".zip" required class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="checksum" class="form-label">Файл контрольної суми (.sha256)</label>
                        <input id="checksum" name="checksum" type="file" accept=".sha256,.txt" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="sha256" class="form-label">…або вставте SHA-256 вручну</label>
                        <input id="sha256" name="sha256" maxlength="200" placeholder="64 шістнадцяткові символи" spellcheck="false" class="form-control font-monospace">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="allow_same" value="1" id="allow_same">
                        <label class="form-check-label small" for="allow_same">перевстановити, якщо це та сама версія</label>
                    </div>
                    <button type="submit" class="btn btn-primary"<?= $busy || !$hasPassphrase ? ' disabled' : '' ?>><?= $busy ? 'Оновлення виконується…' : 'Перевірити й оновити' ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mb-4">
        <div class="card h-100">
            <div class="card-header">Що варто знати</div>
            <div class="card-body small">
                <p>Під час оновлення <code>install.sh</code> виконує <code>composer install</code> — потрібен доступ сервера до packagist.org (або проксі).</p>
                <p>Портал може бути недоступний 1–2 хвилини.</p>
                <p class="mb-0">Хід виконання показується на сторінці завдання; вона оновлюється автоматично.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($jobs !== []): ?>
<h5>Історія оновлень</h5>
<div class="table-responsive">
    <table class="table table-bordered table-sm bg-white">
        <thead><tr><th>Запуск</th><th>Версія</th><th>Хто</th><th>Стан</th><th>Результат</th></tr></thead>
        <tbody>
<?php foreach ($jobs as $j): ?>
            <tr>
                <td class="text-nowrap"><a href="<?= $e($base) ?>/admin/jobs/<?= $e($j['id']) ?>"><?= $e($dt($j['created'])) ?></a></td>
                <td><?= $e($j['version'] ?? '') ?></td>
                <td><?= $e($j['by']) ?></td>
                <td><span class="badge <?= $e($stateBadge[$j['state']] ?? 'bg-secondary') ?>"><?= $e($states[$j['state']] ?? $j['state']) ?></span></td>
                <td class="details text-break"><?= $e($j['message'] ?? '') ?></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
