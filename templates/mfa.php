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
<p class="step">Крок 2 з 2</p>
<h1>Код підтвердження</h1>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
<?php endif ?>
<?php if ($info !== null): ?>
    <div class="alert alert-info" role="status"><?= $e($info) ?></div>
<?php endif ?>
<?php if ($enrolled): ?>
    <p>Відкрийте <strong>Microsoft Authenticator</strong>, знайдіть запис <strong>VPN-GW</strong> і введіть код, що показується зараз.</p>
<?php elseif ($emailPending): ?>
    <p>Введіть код із листа, надісланого на вашу робочу пошту.</p>
<?php else: ?>
    <p>Надішліть код на робочу пошту кнопкою нижче.</p>
<?php endif ?>
<form method="post" action="<?= $e($base) ?>/verify" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="code">6-значний код</label>
    <input id="code" name="code" class="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
           autocomplete="one-time-code" placeholder="000000" autofocus>
    <button type="submit">Підтвердити</button>
</form>
<?php if ($emailAllowed): ?>
    <form method="post" action="<?= $e($base) ?>/verify/email" class="secondary-form">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="link"><?= $enrolled ? 'Немає доступу до застосунку? Надіслати код на пошту' : 'Надіслати код на пошту' ?></button>
    </form>
<?php endif ?>
<p class="hint">Після трьох невдалих спроб вхід буде заблоковано на 15 хвилин.</p>
