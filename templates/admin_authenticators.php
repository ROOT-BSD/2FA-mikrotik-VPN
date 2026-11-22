<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $base
 * @var string $csrf
 * @var list<array{username: string, created_at: string, created_by: string, last_step: int, last_used: int|null, active: bool, email: string}> $rows
 * @var string $q
 * @var int $total
 */
?>
<h3 class="mb-2">Зареєстровані автентифікатори</h3>
<p class="text-muted">Користувачі, які прив’язали Microsoft Authenticator до VPN-GW. Видалення прив’язки — коли співробітник змінив або втратив телефон, звільнився чи код міг потрапити до сторонніх: щоб підключатися до VPN, він має зареєструватися знову з внутрішньої мережі (<code><?= $e($base) ?>/enroll</code>).</p>

<form method="get" action="<?= $e($base) ?>/admin/authenticators" class="card p-3 shadow-sm mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label for="q" class="form-label small mb-1">Пошук за логіном</label>
            <input id="q" name="q" value="<?= $e($q) ?>" class="form-control form-control-sm" maxlength="64" placeholder="ivanenko" spellcheck="false">
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Знайти</button></div>
<?php if ($q !== ''): ?>
        <div class="col-md-2"><a href="<?= $e($base) ?>/admin/authenticators" class="btn btn-outline-secondary btn-sm w-100">Усі</a></div>
<?php endif ?>
    </div>
</form>

<p class="text-muted small"><?= $q === '' ? 'Усього: ' . $e($total) : 'Знайдено: ' . $e(count($rows)) . ' з ' . $e($total) ?></p>
<?php if ($rows === []): ?>
    <p class="text-muted"><?= $q === '' ? 'Ще ніхто не зареєструвався.' : 'Нікого не знайдено.' ?></p>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-bordered table-sm bg-white align-middle authenticators">
        <thead><tr><th>Користувач</th><th>E-mail</th><th>Зареєстровано</th><th>Останній код</th><th>Доступ до VPN</th><th></th></tr></thead>
        <tbody>
<?php foreach ($rows as $r): ?>
            <tr>
                <td class="fw-semibold"><?= $e($r['username']) ?></td>
                <td><?= $r['email'] !== '' ? $e($r['email']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-nowrap"><?= $e($dt($r['created_at'])) ?></td>
                <td class="text-nowrap"><?= $r['last_used'] === null ? '<span class="text-muted">—</span>' : $e($dt($r['last_used'])) ?></td>
                <td><?= $r['active'] ? '<span class="badge bg-success">активний</span>' : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end">
                    <form method="post" action="<?= $e($base) ?>/admin/authenticators/delete" class="m-0">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="username" value="<?= $e($r['username']) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm py-0">Видалити</button>
                    </form>
                </td>
            </tr>
<?php endforeach ?>
        </tbody>
    </table>
</div>
<p class="form-text">«Видалити» прибирає прив’язку одразу, без підтвердження. Вже відкритий доступ до VPN не закривається — щоб відключити користувача зараз, скористайтеся «Відключити» на сторінці «Огляд». У журнал записується подія <code>admin_totp_reset</code>. «Останній код» — час останнього прийнятого коду (зокрема під час реєстрації). «E-mail» — адреса, яку користувач вказав під час реєстрації (туди надіслано пам’ятку); при видаленні автентифікатора вона зберігається і підставляється під час повторної реєстрації.</p>
<?php endif ?>
