<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $admin
 * @var string|null $error
 */
$error ??= null;
?>
<h3 class="mb-2">Зміна пароля</h3>
<p class="text-muted">Обліковий запис: <strong><?= $e($admin) ?></strong>. Мінімум 12 символів; не використовуйте пароль від домену.</p>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/admin/password" class="card p-4 shadow-sm form-card-sm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <div class="mb-3">
        <label for="current" class="form-label">Поточний пароль</label>
        <input id="current" name="current" type="password" class="form-control" required maxlength="256" autocomplete="current-password">
    </div>
    <div class="mb-3">
        <label for="new" class="form-label">Новий пароль</label>
        <input id="new" name="new" type="password" class="form-control" required minlength="12" maxlength="256" autocomplete="new-password">
    </div>
    <div class="mb-3">
        <label for="repeat" class="form-label">Повторіть новий пароль</label>
        <input id="repeat" name="repeat" type="password" class="form-control" required minlength="12" maxlength="256" autocomplete="new-password">
    </div>
    <div><button type="submit" class="btn btn-primary">Змінити пароль</button></div>
</form>
