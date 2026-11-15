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
<h1>Зміна пароля</h1>
<p class="muted">Обліковий запис: <strong><?= $e($admin) ?></strong>. Мінімум 12 символів; не використовуйте пароль від домену.</p>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/admin/password" class="form narrow" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="current">Поточний пароль</label>
    <input id="current" name="current" type="password" required maxlength="256" autocomplete="current-password">
    <label for="new">Новий пароль</label>
    <input id="new" name="new" type="password" required minlength="12" maxlength="256" autocomplete="new-password">
    <label for="repeat">Повторіть новий пароль</label>
    <input id="repeat" name="repeat" type="password" required minlength="12" maxlength="256" autocomplete="new-password">
    <button type="submit" class="btn-inline">Змінити пароль</button>
</form>
