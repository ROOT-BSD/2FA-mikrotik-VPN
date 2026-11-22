<?php
/**
 * One router's connection settings (/admin/routers/edit/{name}, /admin/routers/add).
 *
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var bool $isNew
 * @var string $orig
 * @var array{name: string, host: string, version: int, port: string, transport: string, user: string, list: string} $router
 * @var string|null $hostKey
 * @var bool $hasOwnPassword
 * @var array{api: string, ssh: string} $commonUsers
 * @var array{api: bool, ssh: bool} $commonPasswordSet
 * @var string $defList
 * @var array{lists: array<string, array<string, array{entries: int, rules: int}>>, errors: list<array{name: string, ok: bool, message: string}>, routers: list<string>}|null $mtLists
 * @var string|null $error
 * @var list<array{name: string, ok: bool, message: string}>|null $results
 */
$error ??= null;
$results ??= null;
$tr = $router['transport'];
$action = $base . ($isNew ? '/admin/routers/add' : '/admin/routers/edit/' . $orig);
$plural = static function (int $n, string $one, string $few, string $many): string {
    $m10 = $n % 10;
    $m100 = $n % 100;
    $w = $m10 === 1 && $m100 !== 11 ? $one : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? $few : $many);
    return "$n $w";
};
$info = static fn (array $x): string => $plural($x['entries'], 'адреса', 'адреси', 'адрес') . ', ' . $plural($x['rules'], 'правило', 'правила', 'правил');
$loaded = $mtLists !== null && in_array($orig, $mtLists['routers'], true);
$mine = [];
if ($loaded) {
    foreach ($mtLists['lists'] as $ln => $per) {
        if (isset($per[$orig])) {
            $mine[$ln] = $per[$orig];
        }
    }
}
$own = $router['list'];
?>
<div class="mb-3"><a href="<?= $e($base) ?>/admin/settings/mikrotik" class="text-decoration-none">&larr; Підключення до MikroTik</a></div>
<h3 class="mb-2"><?= $isNew ? 'Новий маршрутизатор' : 'Маршрутизатор ' . $e($orig) ?></h3>
<p class="text-muted">Параметри підключення порталу до цього маршрутизатора. Зберігаються зашифрованими; пароль у браузер не повертається.</p>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert">Не збережено: <?= $e($error) ?></div>
<?php endif ?>
<?php if ($results !== null): ?>
    <div class="alert alert-danger" role="alert"><?php foreach ($results as $r): ?><strong><?= $e($r['name']) ?>:</strong> <?= $e($r['message']) ?><br><?php endforeach ?></div>
<?php endif ?>

<form method="post" action="<?= $e($action) ?>" autocomplete="off" class="form-card-lg">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" name="action" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true">Зберегти</button>
    <div class="card mb-4">
        <div class="card-header">Підключення</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="r_name">Назва</label>
                    <input id="r_name" name="router[name]" value="<?= $e($router['name']) ?>" class="form-control" maxlength="32" pattern="[A-Za-z0-9_\-]+" required placeholder="напр. office2">
                    <div class="form-text">Латиниця, цифри, _ -; показується в журналі.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="r_host">Адреса (IP / DNS)</label>
                    <input id="r_host" name="router[host]" value="<?= $e($router['host']) ?>" class="form-control" maxlength="253" required placeholder="10.0.10.1 або mt-office.company.local">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="r_ver">Версія RouterOS</label>
                    <select id="r_ver" name="router[version]" class="form-select">
                        <option value="7"<?= $router['version'] === 7 ? ' selected' : '' ?>>RouterOS 7</option>
                        <option value="6"<?= $router['version'] === 6 ? ' selected' : '' ?>>RouterOS 6</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="r_tr">Протокол підключення</label>
                    <select id="r_tr" name="router[transport]" class="form-select">
                        <option value="api"<?= $tr === 'api' ? ' selected' : '' ?>>API (REST / API-SSL)</option>
                        <option value="ssh"<?= $tr === 'ssh' ? ' selected' : '' ?>>SSH</option>
                    </select>
                    <div class="form-text">Для API сертифікат маршрутизатора перевіряється CA з .env (MT_CA_FILE).</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="r_port">Порт</label>
                    <input id="r_port" name="router[port]" value="<?= $e($router['port']) ?>" class="form-control" maxlength="5" inputmode="numeric" required placeholder="443">
                    <div class="form-text">REST — 443 / 8443, API-SSL — 8729, SSH — 22.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Обліковий запис на маршрутизаторі</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="r_user">Користувач</label>
                    <input id="r_user" name="router[user]" value="<?= $e($router['user']) ?>" class="form-control" maxlength="64" spellcheck="false" placeholder="<?= $e($commonUsers[$tr] !== '' ? $commonUsers[$tr] : 'vpn_portal') ?>">
                    <div class="form-text"><?= $commonUsers[$tr] !== '' ? 'Порожньо — типовий з .env: ' . $e($commonUsers[$tr]) . '.' : 'Обов’язково (типового користувача не задано).' ?> Група на маршрутизаторі: API — read, write, api / rest-api; SSH — ssh, read, write.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="r_pw">Пароль</label>
                    <input id="r_pw" type="password" name="router[password]" class="form-control" maxlength="256" autocomplete="new-password" placeholder="<?= $hasOwnPassword ? '•••••••• (задано; залиште порожнім, щоб не змінювати)' : ($commonPasswordSet[$tr] ? 'типовий з .env' : 'не задано') ?>">
<?php if ($hasOwnPassword): ?>
                    <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="router[clear_password]" value="1" id="r_cp"><label class="form-check-label" for="r_cp">видалити пароль<?= $commonPasswordSet[$tr] ? ' (використовувати типовий з .env)' : '' ?></label></div>
<?php endif ?>
                    <div class="form-text">Після зміни адреси чи порту пароль треба ввести ще раз.<?= $tr === 'ssh' ? ' Для SSH портал спершу пробує спільний приватний ключ з .env (MT_SSH_KEY), якщо він заданий, потім пароль.' : '' ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Address-list</div>
        <div class="card-body">
<?php if ($loaded): ?>
            <select name="router[list]" class="form-select" id="r_list">
                <option value=""<?= $own === '' ? ' selected' : '' ?>>типовий: <?= $e($defList) ?> — <?= $e(isset($mine[$defList]) ? $info($mine[$defList]) : 'на маршрутизаторі немає') ?></option>
<?php if ($own !== '' && !isset($mine[$own])): ?>
                <option value="<?= $e($own) ?>" selected><?= $e($own) ?> — на маршрутизаторі не знайдено</option>
<?php endif ?>
<?php foreach ($mine as $ln => $x): ?>
                <option value="<?= $e($ln) ?>"<?= $ln === $own ? ' selected' : '' ?>><?= $e($ln . ' — ' . $info($x)) ?></option>
<?php endforeach ?>
                <option value="__new">інший / новий…</option>
            </select>
            <input name="router[list_new]" maxlength="64" class="form-control mt-2" spellcheck="false" placeholder="назва, якщо «інший / новий»" aria-label="Новий address-list">
<?php else: ?>
            <div class="input-group">
                <input name="router[list]" value="<?= $e($own) ?>" maxlength="64" class="form-control" id="r_list" spellcheck="false" placeholder="типовий: <?= $e($defList) ?>">
<?php if (!$isNew): ?>
                <button type="submit" name="action" value="lists" class="btn btn-outline-primary" formnovalidate>Вибрати з MikroTik</button>
<?php endif ?>
            </div>
<?php endif ?>
            <div class="form-text">Список на цьому маршрутизаторі, у який портал додає IP; порожньо — типовий з .env (<?= $e($defList) ?>). На нього має посилатися правило firewall, що дозволяє L2TP/IPsec. «Вибрати з MikroTik» показує списки цього маршрутизатора з кількістю адрес і правил.</div>
        </div>
    </div>

<?php if (!$isNew && $tr === 'ssh'): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center"><span>Ключ хоста SSH</span><?= $hostKey !== null ? '<span class="badge bg-success">підтверджено</span>' : '<span class="badge bg-warning text-dark">не підтверджено</span>' ?></div>
        <div class="card-body">
<?php if ($hostKey !== null): ?>
            <code class="text-break"><?= $e($hostKey) ?></code>
            <div class="form-check small mt-2"><input class="form-check-input" type="checkbox" name="router[reset_key]" value="1" id="r_rk"><label class="form-check-label" for="r_rk">скинути ключ (підтвердити заново кнопкою «Перевірити MikroTik»)</label></div>
<?php else: ?>
            <p class="small text-muted mb-0">Поки ключ не підтверджено, портал до цього маршрутизатора не підключається. Збережіть і натисніть «Перевірити MikroTik» на сторінці «Підключення до MikroTik».</p>
<?php endif ?>
            <div class="form-text">Зміна адреси чи порту скидає збережений ключ.</div>
        </div>
    </div>
<?php endif ?>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" name="action" value="save" class="btn btn-primary"><?= $isNew ? 'Додати маршрутизатор' : 'Зберегти' ?></button>
        <a href="<?= $e($base) ?>/admin/settings/mikrotik" class="btn btn-outline-secondary">Скасувати</a>
    </div>
</form>
