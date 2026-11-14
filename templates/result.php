<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string|null $user
 * @var string|null $ip
 * @var string $until
 */
?>
<div class="result-icon" aria-hidden="true">✓</div>
<h1>Доступ дозволено</h1>
<p class="lead">до <strong><?= $e($until) ?></strong></p>
<dl class="facts">
    <dt>Обліковий запис</dt><dd><?= $e($user) ?></dd>
    <dt>Ваша IP-адреса</dt><dd><?= $e($ip) ?></dd>
</dl>
<div class="alert alert-info">
    Запустіть звичний VPN-клієнт <strong>на цьому ж пристрої і в цій же мережі</strong>.
    Якщо зміните мережу (Wi-Fi ↔ мобільний інтернет) — пройдіть портал ще раз.
</div>
<form method="post" action="<?= $e($base) ?>/revoke">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="danger">Відкликати доступ</button>
</form>
<p class="hint">Натисніть, якщо завершили роботу раніше або користуєтеся чужою мережею. На робочу пошту надіслано лист-підтвердження.</p>
