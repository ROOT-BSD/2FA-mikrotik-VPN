<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string $qr      data: URI of the SVG QR code
 * @var string $secret  formatted secret for manual entry
 * @var string|null $user
 * @var string $email      address for the guide (given before, or from AD)
 * @var bool $mailEnabled
 * @var string|null $error
 */
$error ??= null;
?>
<p class="text-muted small text-center mb-1">Реєстрація автентифікатора · <?= $e($user) ?></p>
<h3 class="mb-4 text-center">Відскануйте QR-код</h3>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/enroll" autocomplete="off" class="card p-4 shadow-sm">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="confirm">
    <p class="small">Microsoft Authenticator → «+» → <strong>«Інший обліковий запис (Google, Facebook тощо)»</strong>.</p>
    <div class="qr text-center mb-3"><img src="<?= $e($qr) ?>" alt="QR-код для Microsoft Authenticator" width="240" height="240" class="img-fluid border rounded"></div>
    <details class="mb-3 small">
        <summary class="link-primary">Камера не працює? Введіть ключ вручну</summary>
        <p class="mt-2 mb-1">У застосунку оберіть «Ввести код вручну», назва — <strong>VPN-GW</strong>, ключ:</p>
        <p class="secret font-monospace bg-light border rounded p-2 mb-0"><?= $e($secret) ?></p>
    </details>
    <div class="mb-3">
        <label for="email" class="form-label">E-mail для пам’ятки</label>
        <input id="email" name="email" type="email" class="form-control" maxlength="254" value="<?= $e($email) ?>" placeholder="ivanenko@company.ua" autocomplete="email" spellcheck="false">
        <div class="form-text">Сюди надішлемо PDF «Як підключатися до робочого VPN» — кроки, які виконуєте щоразу. Адресу буде збережено за вашим обліковим записом.<?= $mailEnabled ? '' : ' Зараз пошта на порталі не налаштована: адресу буде збережено, а лист надішле ІТ-служба пізніше.' ?> Можна залишити порожнім.</div>
    </div>
    <div class="mb-3">
        <label for="code" class="form-label">Код із застосунку для підтвердження</label>
        <input id="code" name="code" class="form-control form-control-lg code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
               autocomplete="one-time-code" placeholder="000000">
    </div>
    <button type="submit" class="btn btn-primary w-100">Завершити реєстрацію</button>
</form>
<p class="form-text text-center mt-3">Не робіть скриншот QR-коду і нікому його не показуйте: він дає змогу генерувати ваші коди.</p>
