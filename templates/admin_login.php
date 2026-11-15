<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $error
 */
$error ??= null;
?>
<p class="step">Панель адміністратора</p>
<h1>Вхід</h1>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/admin/login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="username">Логін адміністратора</label>
    <input id="username" name="username" required maxlength="32" autocapitalize="none" autocorrect="off" spellcheck="false">
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" required maxlength="256">
    <button type="submit">Увійти</button>
</form>
<p class="hint">Доступно лише з внутрішньої мережі. Обліковий запис створюється під час встановлення; скинути пароль: <code>sudo -u vpngw php /srv/vpn-gw/bin/admin.php passwd admin</code></p>
