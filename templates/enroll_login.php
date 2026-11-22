<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $error
 */
$error ??= null;
?>
<p class="text-muted small text-center mb-1">Реєстрація автентифікатора</p>
<h3 class="mb-4 text-center">Прив’язка Microsoft Authenticator</h3>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/enroll" autocomplete="off" class="card p-4 shadow-sm">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="login">
    <ol class="small ps-3 mb-3">
        <li class="mb-1">Встановіть <strong>Microsoft Authenticator</strong> з App Store або Google Play (розробник — Microsoft Corporation).</li>
        <li class="mb-1">Увійдіть нижче доменним логіном і паролем.</li>
        <li>У застосунку: «+» → <strong>«Інший обліковий запис (Google, Facebook тощо)»</strong> → відскануйте QR-код.</li>
    </ol>
    <div class="mb-3">
        <label for="username" class="form-label">Доменний логін</label>
        <input id="username" name="username" class="form-control" required maxlength="128" autocapitalize="none" autocorrect="off" spellcheck="false">
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Пароль</label>
        <input id="password" name="password" type="password" class="form-control" required maxlength="256">
    </div>
    <button type="submit" class="btn btn-primary w-100">Показати QR-код</button>
</form>
