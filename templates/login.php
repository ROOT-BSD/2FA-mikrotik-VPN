<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $error
 */
$error ??= null;
?>
<p class="step">Крок 1 з 2</p>
<h1>Вхід до VPN</h1>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/login" autocomplete="off" novalidate>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="username">Доменний логін</label>
    <input id="username" name="username" required maxlength="128" autocapitalize="none" autocorrect="off"
           spellcheck="false" pattern="[A-Za-z0-9._\-]+" placeholder="ivanenko">
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" required maxlength="256" autocomplete="current-password">
    <button type="submit">Далі</button>
</form>
<p class="hint">Використовуйте ті самі логін і пароль, що й для входу в робочий комп’ютер.</p>
