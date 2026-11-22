<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $error
 */
$error ??= null;
?>
<p class="text-muted small text-center mb-1">Панель адміністратора</p>
<h3 class="mb-4 text-center">Вхід у систему</h3>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/admin/login" autocomplete="off" class="card p-4 shadow-sm">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <div class="mb-3">
        <label for="username" class="form-label">Логін адміністратора</label>
        <input id="username" name="username" class="form-control" required maxlength="32" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Пароль</label>
        <input id="password" name="password" type="password" class="form-control" required maxlength="256">
    </div>
    <button type="submit" class="btn btn-primary w-100">Увійти</button>
</form>
<p class="form-text text-center mt-3">Доступно лише з внутрішньої мережі. Обліковий запис створюється під час встановлення; скинути пароль: <code>sudo -u vpngw php /srv/vpn-gw/bin/admin.php passwd admin</code></p>
