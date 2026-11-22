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
<div class="card p-4 shadow-sm">
    <div class="text-center">
        <div class="result-icon mx-auto mb-2" aria-hidden="true">✓</div>
        <h3 class="mb-1">Доступ дозволено</h3>
        <p class="lead mb-3">до <strong><?= $e($until) ?></strong></p>
    </div>
    <table class="table table-sm mb-3">
        <tr><th class="w-50">Обліковий запис</th><td><?= $e($user) ?></td></tr>
        <tr><th>Ваша IP-адреса</th><td><code><?= $e($ip) ?></code></td></tr>
    </table>
    <div class="alert alert-info small">
        Запустіть звичний VPN-клієнт <strong>на цьому ж пристрої і в цій же мережі</strong>.
        Якщо зміните мережу (Wi-Fi ↔ мобільний інтернет) — пройдіть портал ще раз.
    </div>
    <form method="post" action="<?= $e($base) ?>/revoke">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-outline-danger w-100">Відкликати доступ</button>
    </form>
</div>
<p class="form-text text-center mt-3">Натисніть, якщо завершили роботу раніше або користуєтеся чужою мережею. На робочу пошту надіслано лист-підтвердження.</p>
