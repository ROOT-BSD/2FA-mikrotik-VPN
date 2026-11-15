<?php
/**
 * @var callable(mixed): string $e
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
?>
<h1>Оновлення</h1>
<p>Встановлена версія: <strong><?= $e($version) ?></strong></p>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<?php if (!$hasPassphrase): ?>
    <div class="alert alert-warning">Перед оновленням автоматично створюється резервна копія — спершу <a href="<?= $e($base) ?>/admin/settings/backup">задайте пароль шифрування копій</a>.</div>
<?php endif ?>

<section class="box">
    <h2>Завантажити нову версію</h2>
    <ol class="steps small">
        <li>Отримайте архів релізу <code>vpn-gw-X.Y.zip</code> і файл контрольної суми <code>vpn-gw-X.Y.zip.sha256</code>.</li>
        <li>Завантажте обидва файли нижче. Портал перевірить SHA-256, структуру архіву і версію.</li>
        <li>Далі автоматично: резервна копія → знімок поточної версії → <code>install.sh</code> → перевірка. Якщо щось піде не так — відкат до попередньої версії.</li>
    </ol>
    <form method="post" action="<?= $e($base) ?>/admin/update" enctype="multipart/form-data" class="form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <label for="archive">Архів релізу (.zip, до <?= $e($maxMb) ?> МБ)</label>
        <input id="archive" name="archive" type="file" accept=".zip" required>
        <label for="checksum">Файл контрольної суми (.sha256)</label>
        <input id="checksum" name="checksum" type="file" accept=".sha256,.txt">
        <label for="sha256">…або вставте SHA-256 вручну</label>
        <input id="sha256" name="sha256" maxlength="200" placeholder="64 шістнадцяткові символи" spellcheck="false">
        <label class="check small"><input type="checkbox" name="allow_same" value="1"> перевстановити, якщо це та сама версія</label>
        <div class="actions">
            <button type="submit" class="btn-inline"<?= $busy || !$hasPassphrase ? ' disabled' : '' ?>><?= $busy ? 'Оновлення виконується…' : 'Перевірити й оновити' ?></button>
        </div>
    </form>
    <p class="help">Під час оновлення <code>install.sh</code> виконує <code>composer install</code> — потрібен доступ сервера до packagist.org (або проксі). Портал може бути недоступний 1–2 хвилини.</p>
</section>

<?php if ($jobs !== []): ?>
<section class="box">
    <h2>Історія оновлень</h2>
    <table class="table">
        <thead><tr><th>Запуск</th><th>Версія</th><th>Хто</th><th>Стан</th><th>Результат</th></tr></thead>
        <tbody>
<?php foreach ($jobs as $j): ?>
            <tr>
                <td class="nowrap"><a href="<?= $e($base) ?>/admin/jobs/<?= $e($j['id']) ?>"><?= $e($j['created']) ?></a></td>
                <td><?= $e($j['version'] ?? '') ?></td>
                <td><?= $e($j['by']) ?></td>
                <td><span class="badge <?= $e($j['state']) ?>"><?= $e($states[$j['state']] ?? $j['state']) ?></span></td>
                <td class="details"><?= $e($j['message'] ?? '') ?></td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</section>
<?php endif ?>
