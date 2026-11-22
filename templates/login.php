<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $error
 */
$error ??= null;
?>
<p class="text-muted small text-center mb-1">Крок 1 з 2</p>
<h3 class="mb-4 text-center">Вхід до VPN</h3>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/login" autocomplete="off" novalidate class="card p-4 shadow-sm">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <div class="mb-3">
        <label for="username" class="form-label">Доменний логін</label>
        <input id="username" name="username" class="form-control" required maxlength="128" autocapitalize="none" autocorrect="off"
               spellcheck="false" pattern="[A-Za-z0-9._\-]+" placeholder="ivanenko" autofocus>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Пароль</label>
        <input id="password" name="password" type="password" class="form-control" required maxlength="256" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary w-100">Далі</button>
</form>
<p class="form-text text-center mt-3">Використовуйте ті самі логін і пароль, що й для входу в робочий комп’ютер.</p>
