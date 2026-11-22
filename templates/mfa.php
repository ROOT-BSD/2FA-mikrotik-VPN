<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var bool $enrolled
 * @var bool $emailAllowed
 * @var bool $emailPending
 * @var string|null $error
 * @var string|null $info
 */
$error ??= null;
$info ??= null;
?>
<p class="text-muted small text-center mb-1">Крок 2 з 2</p>
<h3 class="mb-4 text-center">Код підтвердження</h3>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<?php if ($info !== null): ?>
    <div class="alert alert-info" role="status"><?= $e($info) ?></div>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/verify" autocomplete="off" class="card p-4 shadow-sm">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($enrolled): ?>
    <p>Відкрийте <strong>Microsoft Authenticator</strong>, знайдіть запис <strong>VPN-GW</strong> і введіть код, що показується зараз.</p>
<?php elseif ($emailPending): ?>
    <p>Введіть код із листа, надісланого на вашу робочу пошту.</p>
<?php else: ?>
    <p>Надішліть код на робочу пошту кнопкою нижче.</p>
<?php endif ?>
    <div class="mb-3">
        <label for="code" class="form-label">6-значний код</label>
        <input id="code" name="code" class="form-control form-control-lg code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
               autocomplete="one-time-code" placeholder="000000" autofocus>
    </div>
    <button type="submit" class="btn btn-primary w-100">Підтвердити</button>
</form>
<?php if ($emailAllowed): ?>
    <form method="post" action="<?= $e($base) ?>/verify/email" class="text-center mt-3">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-link btn-sm"><?= $enrolled ? 'Немає доступу до застосунку? Надіслати код на пошту' : 'Надіслати код на пошту' ?></button>
    </form>
<?php endif ?>
<p class="form-text text-center mt-3">Після трьох невдалих спроб вхід буде заблоковано на 15 хвилин.</p>
