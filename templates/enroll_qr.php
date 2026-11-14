<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string $qr      data: URI of the SVG QR code
 * @var string $secret  formatted secret for manual entry
 * @var string|null $user
 * @var string|null $error
 */
$error ??= null;
?>
<p class="step">Реєстрація автентифікатора · <?= $e($user) ?></p>
<h1>Відскануйте QR-код</h1>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<p>Microsoft Authenticator → «+» → <strong>«Інший обліковий запис (Google, Facebook тощо)»</strong>.</p>
<div class="qr"><img src="<?= $e($qr) ?>" alt="QR-код для Microsoft Authenticator" width="240" height="240"></div>
<details class="manual">
    <summary>Камера не працює? Введіть ключ вручну</summary>
    <p>У застосунку оберіть «Ввести код вручну», назва — <strong>VPN-GW</strong>, ключ:</p>
    <p class="secret"><?= $e($secret) ?></p>
</details>
<form method="post" action="<?= $e($base) ?>/enroll" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="confirm">
    <label for="code">Код із застосунку для підтвердження</label>
    <input id="code" name="code" class="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
           autocomplete="one-time-code" placeholder="000000">
    <button type="submit">Завершити реєстрацію</button>
</form>
<p class="hint">Не робіть скриншот QR-коду і нікому його не показуйте: він дає змогу генерувати ваші коди.</p>
