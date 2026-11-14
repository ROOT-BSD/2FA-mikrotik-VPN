<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $error
 */
$error ??= null;
?>
<p class="step">Реєстрація автентифікатора</p>
<h1>Прив’язка Microsoft Authenticator</h1>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<ol class="steps">
    <li>Встановіть <strong>Microsoft Authenticator</strong> з App Store або Google Play (розробник — Microsoft Corporation).</li>
    <li>Увійдіть нижче доменним логіном і паролем.</li>
    <li>У застосунку: «+» → <strong>«Інший обліковий запис (Google, Facebook тощо)»</strong> → відскануйте QR-код.</li>
</ol>
<form method="post" action="<?= $e($base) ?>/enroll" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="login">
    <label for="username">Доменний логін</label>
    <input id="username" name="username" required maxlength="64" autocapitalize="none" autocorrect="off" spellcheck="false">
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" required maxlength="256">
    <button type="submit">Показати QR-код</button>
</form>
