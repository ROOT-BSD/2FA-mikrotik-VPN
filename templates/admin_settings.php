<?php
/**
 * @var callable(mixed): string $e
 * @var callable(mixed, bool=): string $dt
 * @var string $base
 * @var string $csrf
 * @var string $section
 * @var array{title: string, intro: string, fields: list<array{key: string, label: string, type: string, help?: string, options?: array<string, string>}>} $schema
 * @var array<string, string> $values
 * @var array<string, string> $sources
 * @var array<string, bool> $secretSet
 * @var list<array<string, mixed>> $routers  rows of the cards (as entered, on an error)
 * @var list<array{name: string, host: string, version: int, port: int, transport: string, user: string, list: string}> $savedRouters
 * @var array<string, string> $categories
 * @var array<string, array{updated_at: string, updated_by: string}> $meta
 * @var array<string, string> $hostKeys
 * @var list<string> $ownPasswords
 * @var array{api: string, ssh: string} $commonUsers
 * @var array{lists: array<string, array<string, array{entries: int, rules: int}>>, errors: list<array{name: string, ok: bool, message: string}>, routers: list<string>}|null $mtLists
 * @var string|null $error
 * @var list<array{name: string, ok: bool, message: string}>|null $results
 */
$error ??= null;
$results ??= null;
$mtLists ??= null;
$plural = static function (int $n, string $one, string $few, string $many): string {
    $m10 = $n % 10;
    $m100 = $n % 100;
    $w = $m10 === 1 && $m100 !== 11 ? $one : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? $few : $many);
    return "$n $w";
};
$action = $base . '/admin/settings/' . $section;
$srcLabel = ['panel' => 'змінено в панелі', 'env' => 'з .env', 'default' => 'типове'];
?>
<h3 class="mb-2"><?= $e($schema['title']) ?></h3>
<p class="text-muted"><?= $e($schema['intro']) ?></p>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert">Не збережено: <?= $e($error) ?></div>
<?php endif ?>
<?php if ($results !== null): ?>
    <div class="card mb-4">
        <div class="card-header">Результат перевірки</div>
        <ul class="list-group list-group-flush checks">
<?php foreach ($results as $r): ?>
            <li class="list-group-item <?= $r['ok'] ? 'ok list-group-item-success' : 'fail list-group-item-danger' ?>"><strong><?= $e($r['name']) ?>:</strong> <?= $e($r['message']) ?></li>
<?php endforeach ?>
<?php if ($results === []): ?>
            <li class="list-group-item fail list-group-item-danger">Немає що перевіряти.</li>
<?php endif ?>
        </ul>
    </div>
<?php endif ?>

<form method="post" action="<?= $e($action) ?>" class="settings-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" name="action" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true">Зберегти</button>
<?php $cardOpen = false; $prevType = ''; ?>
<?php foreach ($schema['fields'] as $f): $k = $f['key']; $id = 'f_' . strtolower($k); ?>
<?php if ($f['type'] === 'hostkeys' || $f['type'] === 'routerpasswords' || ($f['hidden'] ?? false)) { continue; } ?>
<?php if (!$cardOpen || isset($f['group']) || $f['type'] === 'routers' || $prevType === 'routers'): ?>
<?php if ($cardOpen): ?>
        </div>
    </div>
<?php endif; $cardOpen = true; ?>
    <div class="<?= $f['type'] === 'routers' ? 'mb-4' : 'card mb-4' ?>">
<?php if ($f['type'] !== 'routers'): ?>
        <div class="card-header"><?= $e($f['group'] ?? 'Основні параметри') ?></div>
<?php endif ?>
        <div class="<?= $f['type'] === 'routers' ? '' : 'card-body' ?>">
<?php endif; $prevType = $f['type']; ?>
    <div class="field mb-3">
        <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
            <label for="<?= $e($id) ?>" class="form-label fw-semibold mb-1"><?= $e($f['label']) ?></label>
            <span class="small text-muted src src-<?= $e($sources[$k]) ?>" title="<?= isset($meta[$k]) ? $e('Змінив ' . $meta[$k]['updated_by'] . ' ' . $dt($meta[$k]['updated_at'])) : '' ?>"><code><?= $e($k) ?></code> · <?= $sources[$k] === 'panel' ? '<span class="badge bg-primary-subtle text-primary-emphasis">' . $e($srcLabel['panel']) . '</span>' : $e($srcLabel[$sources[$k]] ?? '') ?></span>
        </div>
<?php if ($f['type'] === 'routers'):
    $defList = $values['MT_LIST'] ?? '';
?>
        <div class="row g-3 router-cards">
<?php foreach ($savedRouters as $r): $rn = $r['name']; $tr = $r['transport'] === 'ssh' ? 'ssh' : 'api'; ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 router-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><?= $e($rn) ?></span>
<?php if ($tr === 'ssh'): ?>
                        <?= isset($hostKeys[$rn]) ? '<span class="badge bg-success">ключ підтверджено</span>' : '<span class="badge bg-warning text-dark">ключ не підтверджено</span>' ?>
<?php endif ?>
                    </div>
                    <div class="card-body">
                        <dl class="row g-2 mb-0 router-facts">
                            <dt class="col-5">Назва</dt><dd class="col-7"><?= $e($rn) ?></dd>
                            <dt class="col-5">Адреса (IP / DNS)</dt><dd class="col-7 host"><?= $e($r['host']) ?></dd>
                            <dt class="col-5">Версія RouterOS</dt><dd class="col-7"><?= $e($r['version']) ?></dd>
                            <dt class="col-5">Протокол підключення</dt><dd class="col-7"><?= $tr === 'ssh' ? 'SSH' : 'API (' . ($r['version'] === 6 ? 'API-SSL' : 'REST') . ')' ?></dd>
                            <dt class="col-5">Address-list</dt><dd class="col-7 address-list"><strong><?= $e($r['list'] !== '' ? $r['list'] : $defList) ?></strong><?= $r['list'] === '' ? ' <span class="text-muted small fw-normal">(типовий)</span>' : '' ?></dd>
                        </dl>
                    </div>
                    <div class="card-footer bg-transparent">
                        <a href="<?= $e($base) ?>/admin/routers/edit/<?= $e($rn) ?>" class="btn btn-outline-primary btn-sm">Редагувати</a>
                    </div>
                </div>
            </div>
<?php endforeach ?>
            <div class="col-md-6 col-xl-4">
                <a href="<?= $e($base) ?>/admin/routers/add" class="card h-100 router-card border-dashed text-decoration-none d-flex align-items-center justify-content-center add-router">
                    <span class="py-4 text-primary">+ Додати маршрутизатор</span>
                </a>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">Підключені маршрутизатори</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 connected-routers">
                    <thead class="table-light"><tr><th>Назва</th><th>Адреса</th><th>RouterOS</th><th>Протокол</th><th>Користувач</th><th>Address-list</th><th>Ключ SSH</th><th></th></tr></thead>
                    <tbody>
<?php foreach ($savedRouters as $r): $rn = $r['name']; $tr = $r['transport'] === 'ssh' ? 'ssh' : 'api'; ?>
                        <tr>
                            <td class="fw-semibold"><?= $e($rn) ?></td>
                            <td><code><?= $e($r['host']) ?>:<?= $e($r['port']) ?></code></td>
                            <td><?= $e($r['version']) ?></td>
                            <td><?= $tr === 'ssh' ? 'SSH' : 'API' ?></td>
                            <td><?= $r['user'] !== '' ? $e($r['user']) : '<span class="text-muted">типовий</span>' ?><?= in_array($rn, $ownPasswords, true) ? ' <span class="badge bg-light text-dark border">свій пароль</span>' : '' ?></td>
                            <td><?= $r['list'] !== '' ? $e($r['list']) : '<span class="text-muted">' . $e($defList) . '</span>' ?></td>
                            <td><?= $tr !== 'ssh' ? '<span class="text-muted">—</span>' : (isset($hostKeys[$rn]) ? '<span class="badge bg-success">підтверджено</span>' : '<span class="badge bg-warning text-dark">не підтверджено</span>') ?></td>
                            <td class="text-end"><button type="submit" form="router-delete" name="router" value="<?= $e($rn) ?>" class="btn btn-outline-danger btn-sm py-0"<?= count($savedRouters) < 2 ? ' disabled title="Єдиний маршрутизатор видалити не можна"' : '' ?>>Видалити</button></td>
                        </tr>
<?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">«Видалити» одразу прибирає маршрутизатор з налаштувань разом з його власним паролем і ключем SSH; інші незбережені зміни на сторінці не застосовуються. Адреси, які портал уже додав на цьому MikroTik, той видалить сам після тайм-ауту.</div>
        </div>
<?php elseif ($f['type'] === 'mtlist' && $mtLists === null): ?>
        <div class="input-group form-card-md">
            <input id="<?= $e($id) ?>" name="<?= $e($k) ?>" value="<?= $e($values[$k]) ?>" maxlength="64" spellcheck="false" class="form-control">
            <button type="submit" name="action" value="lists" class="btn btn-outline-primary" formnovalidate>Вибрати з MikroTik</button>
        </div>
<?php elseif ($f['type'] === 'mtlist'): $cur = $values[$k]; $okRouters = $mtLists['routers']; ?>
        <select id="<?= $e($id) ?>" name="<?= $e($k) ?>" class="form-select">
<?php if ($cur !== '' && !isset($mtLists['lists'][$cur])): ?>
            <option value="<?= $e($cur) ?>" selected><?= $e($cur) ?> — поточний; на маршрутизаторах не знайдено</option>
<?php endif ?>
<?php foreach ($mtLists['lists'] as $ln => $per):
    $parts = [];
    foreach ($okRouters as $rn) {
        $parts[] = isset($per[$rn])
            ? $rn . ': ' . $plural($per[$rn]['entries'], 'адреса', 'адреси', 'адрес') . ', ' . $plural($per[$rn]['rules'], 'правило', 'правила', 'правил')
            : $rn . ': немає';
    } ?>
            <option value="<?= $e($ln) ?>"<?= $ln === $cur ? ' selected' : '' ?>><?= $e($ln . ' — ' . implode('; ', $parts)) ?></option>
<?php endforeach ?>
            <option value="__new">Новий список (ввести назву нижче)…</option>
        </select>
        <input name="<?= $e($k) ?>__new" maxlength="64" spellcheck="false" class="form-control mt-2 mt-new" placeholder="назва нового списку — лише якщо вибрано «Новий список»" aria-label="Назва нового списку">
        <p class="form-text">Прочитано з: <?= $e($okRouters === [] ? 'жодного маршрутизатора' : implode(', ', $okRouters)) ?>. «Правила» — скільки правил firewall (filter, nat, mangle, raw) посилаються на список: портал має працювати зі списком, який використовується в правилі, що дозволяє L2TP/IPsec. Список без адрес — нормально: адреси з’являються після входу користувачів. Вибір застосовується після «Зберегти».</p>
<?php elseif ($f['type'] === 'bool'): ?>
        <input type="hidden" name="<?= $e($k) ?>" value="0">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="<?= $e($id) ?>" name="<?= $e($k) ?>" value="1"<?= filter_var($values[$k], FILTER_VALIDATE_BOOL) ? ' checked' : '' ?>><label class="form-check-label" for="<?= $e($id) ?>">увімкнено</label></div>
<?php elseif ($f['type'] === 'select'): ?>
        <select id="<?= $e($id) ?>" name="<?= $e($k) ?>" class="form-select form-card-md">
<?php foreach ($f['options'] ?? [] as $ov => $ol): ?>
            <option value="<?= $e($ov) ?>"<?= $values[$k] === $ov ? ' selected' : '' ?>><?= $e($ol) ?></option>
<?php endforeach ?>
        </select>
<?php elseif ($f['type'] === 'categories'): $picked = array_map('trim', explode(',', $values[$k])); ?>
        <div class="checklist">
<?php foreach ($categories as $cv => $cl): $fixed = in_array($cv, ['admin', 'system'], true); ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="cat_<?= $e($cv) ?>" name="<?= $e($k) ?>[]" value="<?= $e($cv) ?>"<?= $fixed || in_array($cv, $picked, true) ? ' checked' : '' ?><?= $fixed ? ' disabled' : '' ?>><label class="form-check-label" for="cat_<?= $e($cv) ?>"><?= $e($cl) ?></label></div>
<?php endforeach ?>
        </div>
<?php elseif ($f['type'] === 'secretarea'): ?>
        <textarea id="<?= $e($id) ?>" name="<?= $e($k) ?>" class="form-control font-monospace small" rows="5" spellcheck="false" autocomplete="off" placeholder="<?= ($secretSet[$k] ?? false) ? 'ключ задано; залиште порожнім, щоб не змінювати' : '-----BEGIN OPENSSH PRIVATE KEY----- …' ?>"></textarea>
<?php if ($secretSet[$k] ?? false): ?>
        <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" id="<?= $e($id) ?>__clear" name="<?= $e($k) ?>__clear" value="1"><label class="form-check-label" for="<?= $e($id) ?>__clear">очистити</label></div>
<?php endif ?>
<?php elseif ($f['type'] === 'secret'): ?>
        <input id="<?= $e($id) ?>" name="<?= $e($k) ?>" type="password" class="form-control form-card-md" maxlength="256" autocomplete="new-password" placeholder="<?= ($secretSet[$k] ?? false) ? '•••••••• (задано; залиште порожнім, щоб не змінювати)' : 'не задано' ?>">
<?php if ($secretSet[$k] ?? false): ?>
        <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" id="<?= $e($id) ?>__clear" name="<?= $e($k) ?>__clear" value="1"><label class="form-check-label" for="<?= $e($id) ?>__clear">очистити</label></div>
<?php endif ?>
<?php else: ?>
        <input id="<?= $e($id) ?>" name="<?= $e($k) ?>" value="<?= $e($values[$k]) ?>" maxlength="1024" class="form-control<?= $f['type'] === 'int' ? ' form-card-xs" inputmode="numeric' : ' form-card-md' ?>">
<?php endif ?>
<?php if (isset($f['help'])): ?>
        <div class="form-text"><?= $e($f['help']) ?></div>
<?php endif ?>
<?php if ($sources[$k] === 'panel'): ?>
        <button type="submit" name="action" value="reset:<?= $e($k) ?>" class="btn btn-link btn-sm p-0" formnovalidate>повернути значення з .env</button>
<?php endif ?>
    </div>
<?php endforeach ?>
<?php if ($cardOpen): ?>
        </div>
    </div>
<?php endif ?>
    <div class="actions mb-4">
        <button type="submit" name="action" value="save" class="btn btn-primary">Зберегти</button>
    </div>
</form>
<?php if ($section === 'mikrotik'): ?>
<form method="post" action="<?= $e($action) ?>" id="router-delete" class="d-none">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="delete_router">
</form>
<?php endif ?>

<?php if ($section === 'mikrotik'): ?>
<div class="card mb-4">
    <div class="card-header">Перевірка з’єднання</div>
    <div class="card-body">
        <p class="small text-muted">Підключається до кожного маршрутизатора зі збереженими налаштуваннями і читає його address-list. Нічого не змінює.</p>
        <form method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" name="action" value="test" class="btn btn-outline-primary btn-sm">Перевірити MikroTik</button>
        </form>
    </div>
</div>
<?php elseif ($section === 'ad'): ?>
<div class="card mb-4">
    <div class="card-header">Перевірка входу</div>
    <div class="card-body">
        <p class="small text-muted">Вхід тестовим обліковим записом зі збереженими налаштуваннями. Перевіряє з’єднання з контролером домену у вибраному режимі, пароль і членство в групі VPN. Пароль ніде не зберігається.</p>
        <form method="post" action="<?= $e($action) ?>" class="row g-2 align-items-center" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <div class="col-md-4"><input name="test_user" class="form-control form-control-sm" placeholder="user або user@домен" maxlength="64" aria-label="Логін" autocapitalize="none" spellcheck="false"></div>
            <div class="col-md-4"><input name="test_password" type="password" class="form-control form-control-sm" placeholder="пароль" maxlength="256" aria-label="Пароль" autocomplete="off"></div>
            <div class="col-md-auto"><button type="submit" name="action" value="test" class="btn btn-outline-primary btn-sm">Перевірити AD</button></div>
        </form>
    </div>
</div>
<?php elseif ($section === 'mail'): ?>
<div class="card mb-4">
    <div class="card-header">Тестовий лист</div>
    <div class="card-body">
        <form method="post" action="<?= $e($action) ?>" class="row g-2 align-items-center" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <div class="col-md-5"><input name="test_to" type="email" class="form-control form-control-sm" placeholder="адреса отримувача" maxlength="254" aria-label="Адреса"></div>
            <div class="col-md-auto"><button type="submit" name="action" value="test" class="btn btn-outline-primary btn-sm">Надіслати</button></div>
        </form>
    </div>
</div>
<?php endif ?>
