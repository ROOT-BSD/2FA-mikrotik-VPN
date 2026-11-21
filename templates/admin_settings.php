<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $csrf
 * @var string $section
 * @var array{title: string, intro: string, fields: list<array{key: string, label: string, type: string, help?: string, options?: array<string, string>}>} $schema
 * @var array<string, string> $values
 * @var array<string, string> $sources
 * @var array<string, bool> $secretSet
 * @var list<array<string, mixed>> $routers
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
$rows = $routers;
for ($i = count($rows); $i < count($routers) + 2; $i++) {
    $rows[] = ['name' => '', 'host' => '', 'version' => 7, 'port' => '', 'transport' => 'api', 'user' => '', 'list' => ''];
}
?>
<h1><?= $e($schema['title']) ?></h1>
<p class="muted"><?= $e($schema['intro']) ?></p>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert">Не збережено: <?= $e($error) ?></div>
<?php endif ?>
<?php if ($results !== null): ?>
    <section class="box">
        <h2>Результат перевірки</h2>
        <ul class="checks">
<?php foreach ($results as $r): ?>
            <li class="<?= $r['ok'] ? 'ok' : 'fail' ?>"><strong><?= $e($r['name']) ?>:</strong> <?= $e($r['message']) ?></li>
<?php endforeach ?>
<?php if ($results === []): ?>
            <li class="fail">Немає що перевіряти.</li>
<?php endif ?>
        </ul>
    </section>
<?php endif ?>

<form method="post" action="<?= $e($action) ?>" class="form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" name="action" value="save" class="sr-only" tabindex="-1" aria-hidden="true">Зберегти</button>
<?php foreach ($schema['fields'] as $f): $k = $f['key']; $id = 'f_' . strtolower($k); ?>
<?php if ($f['type'] === 'hostkeys' || $f['type'] === 'routerpasswords') { continue; } ?>
<?php if (isset($f['group'])): ?>
    <h2 class="group"><?= $e($f['group']) ?></h2>
<?php endif ?>
    <div class="field">
        <div class="field-head">
            <label for="<?= $e($id) ?>"><?= $e($f['label']) ?></label>
            <span class="src src-<?= $e($sources[$k]) ?>" title="<?= isset($meta[$k]) ? $e('Змінив ' . $meta[$k]['updated_by'] . ' ' . $meta[$k]['updated_at']) : '' ?>"><code><?= $e($k) ?></code> · <?= $e($srcLabel[$sources[$k]] ?? '') ?></span>
        </div>
<?php if ($f['type'] === 'routers'): ?>
        <table class="table routers">
            <thead><tr><th>Назва</th><th>Адреса (IP / DNS)</th><th>Версія</th><th>Протокол</th><th>Порт</th><th>Користувач</th><th>Пароль</th><th>Ключ SSH</th><th>Видалити</th></tr></thead>
            <tbody>
<?php foreach ($rows as $i => $r): ?>
<?php $rn = (string) ($r['name'] ?? ''); $tr = ($r['transport'] ?? 'api') === 'ssh' ? 'ssh' : 'api'; $hasOwn = $rn !== '' && in_array($rn, $ownPasswords, true); ?>
                <tr>
                    <td><input type="hidden" name="routers[<?= $e($i) ?>][orig]" value="<?= $e($rn) ?>"><input name="routers[<?= $e($i) ?>][name]" value="<?= $e($r['name'] ?? '') ?>" maxlength="32" pattern="[A-Za-z0-9_\-]*" aria-label="Назва"></td>
                    <td><input name="routers[<?= $e($i) ?>][host]" value="<?= $e($r['host'] ?? '') ?>" maxlength="253" aria-label="Адреса"></td>
                    <td><select name="routers[<?= $e($i) ?>][version]" aria-label="Версія">
                        <option value="7"<?= ($r['version'] ?? 7) === 7 ? ' selected' : '' ?>>RouterOS 7</option>
                        <option value="6"<?= ($r['version'] ?? 7) === 6 ? ' selected' : '' ?>>RouterOS 6</option>
                    </select></td>
                    <td><select name="routers[<?= $e($i) ?>][transport]" aria-label="Протокол">
                        <option value="api"<?= ($r['transport'] ?? 'api') === 'api' ? ' selected' : '' ?>>API</option>
                        <option value="ssh"<?= ($r['transport'] ?? 'api') === 'ssh' ? ' selected' : '' ?>>SSH</option>
                    </select></td>
                    <td><input name="routers[<?= $e($i) ?>][port]" value="<?= $e($r['port'] ?? '') ?>" maxlength="5" inputmode="numeric" class="w-port" aria-label="Порт" placeholder="<?= ($r['name'] ?? '') === '' ? '443' : '' ?>"></td>
                    <td><input name="routers[<?= $e($i) ?>][user]" value="<?= $e($r['user'] ?? '') ?>" maxlength="64" class="w-user" aria-label="Користувач" autocomplete="off" spellcheck="false" placeholder="<?= $e($commonUsers[$tr] !== '' ? $commonUsers[$tr] : 'типовий') ?>" title="<?= $e('Порожньо — типовий користувач ' . ($tr === 'ssh' ? 'SSH' : 'API') . ($commonUsers[$tr] !== '' ? ': ' . $commonUsers[$tr] : '')) ?>"></td>
                    <td class="pw"><input type="password" name="routers[<?= $e($i) ?>][password]" maxlength="256" class="w-pass" aria-label="Пароль" autocomplete="new-password" placeholder="<?= $hasOwn ? '•••• власний' : 'типовий' ?>">
<?php if ($hasOwn): ?>
                        <label class="check small"><input type="checkbox" name="routers[<?= $e($i) ?>][clear_password]" value="1"> типовий</label>
<?php endif ?></td>
                    <td class="fp"><?php if (($r['transport'] ?? 'api') === 'ssh' && ($r['name'] ?? '') !== ''): ?>
<?php if (isset($hostKeys[$r['name']])): ?>
                        <code title="<?= $e($hostKeys[$r['name']]) ?>"><?= $e(substr($hostKeys[$r['name']], 0, 19)) ?>…</code>
                        <label class="check small"><input type="checkbox" name="routers[<?= $e($i) ?>][reset_key]" value="1"> скинути</label>
<?php else: ?>
                        <span class="badge warn">не підтверджено</span>
<?php endif ?>
<?php endif ?></td>
                    <td class="center"><?php if (($r['name'] ?? '') !== ''): ?><input type="checkbox" name="routers[<?= $e($i) ?>][delete]" value="1" aria-label="Видалити"><?php endif ?></td>
                </tr>
<?php $own = (string) ($r['list'] ?? ''); $defList = $values['MT_LIST'] ?? ''; $loaded = $mtLists !== null && $rn !== '' && in_array($rn, $mtLists['routers'], true); ?>
                <tr class="sub">
                    <td colspan="10">
                        <span class="sub-label">Address-list:</span>
<?php if ($loaded):
    $mine = [];
    foreach ($mtLists['lists'] as $ln => $per) {
        if (isset($per[$rn])) {
            $mine[$ln] = $per[$rn];
        }
    }
    $info = static fn (array $x): string => $plural($x['entries'], 'адреса', 'адреси', 'адрес') . ', ' . $plural($x['rules'], 'правило', 'правила', 'правил');
?>
                        <select name="routers[<?= $e($i) ?>][list]" class="w-list" aria-label="Address-list <?= $e($rn) ?>">
                            <option value=""<?= $own === '' ? ' selected' : '' ?>>типовий: <?= $e($defList) ?> — <?= $e(isset($mine[$defList]) ? $info($mine[$defList]) : 'на маршрутизаторі немає') ?></option>
<?php if ($own !== '' && !isset($mine[$own])): ?>
                            <option value="<?= $e($own) ?>" selected><?= $e($own) ?> — на маршрутизаторі не знайдено</option>
<?php endif ?>
<?php foreach ($mine as $ln => $x): ?>
                            <option value="<?= $e($ln) ?>"<?= $ln === $own ? ' selected' : '' ?>><?= $e($ln . ' — ' . $info($x)) ?></option>
<?php endforeach ?>
                            <option value="__new">інший / новий…</option>
                        </select>
                        <input name="routers[<?= $e($i) ?>][list_new]" maxlength="64" class="w-listnew" spellcheck="false" placeholder="назва, якщо «інший / новий»" aria-label="Новий address-list <?= $e($rn) ?>">
<?php else: ?>
                        <input name="routers[<?= $e($i) ?>][list]" value="<?= $e($own) ?>" maxlength="64" class="w-listnew" spellcheck="false" placeholder="типовий: <?= $e($defList) ?>" aria-label="Address-list">
<?php if ($mtLists !== null && $rn !== ''): ?>
                        <span class="badge warn">списки не прочитано</span>
<?php endif ?>
<?php endif ?>
                    </td>
                </tr>
<?php endforeach ?>
            </tbody>
        </table>
        <p><button type="submit" name="action" value="lists" class="btn-inline secondary small-btn" formnovalidate>Вибрати списки з MikroTik</button></p>
        <p class="help">Користувач і пароль — власні облікові дані маршрутизатора; порожньо — типові для його протоколу (розділи «API» і «SSH» нижче; сірим у полі показано типового користувача). Address-list — список цього маршрутизатора, у який портал додає IP; порожньо — типовий (нижче). Кнопка «Вибрати з MikroTik» показує списки кожного маршрутизатора (спершу збережіть зміни в таблиці). Збережений пароль не показується: залиште поле порожнім, щоб не змінювати, або позначте «типовий», щоб видалити власний пароль. Після зміни адреси чи порту пароль треба ввести ще раз. Порожні рядки ігноруються. Типові порти: REST (www-ssl) — 443, API-SSL — 8729, SSH — 22. Ключ SSH маршрутизатора запам’ятовується кнопкою «Перевірити MikroTik»; доки він не підтверджений, портал до цього маршрутизатора не підключається. Зміна адреси чи порту скидає збережений ключ.</p>
<?php elseif ($f['type'] === 'mtlist' && $mtLists === null): ?>
        <div class="inline-row">
            <input id="<?= $e($id) ?>" name="<?= $e($k) ?>" value="<?= $e($values[$k]) ?>" maxlength="64" spellcheck="false">
            <button type="submit" name="action" value="lists" class="btn-inline secondary" formnovalidate>Вибрати з MikroTik</button>
        </div>
<?php elseif ($f['type'] === 'mtlist'): $cur = $values[$k]; $okRouters = $mtLists['routers']; ?>
        <select id="<?= $e($id) ?>" name="<?= $e($k) ?>">
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
        <input name="<?= $e($k) ?>__new" maxlength="64" spellcheck="false" class="mt-new" placeholder="назва нового списку — лише якщо вибрано «Новий список»" aria-label="Назва нового списку">
        <p class="help">Прочитано з: <?= $e($okRouters === [] ? 'жодного маршрутизатора' : implode(', ', $okRouters)) ?>. «Правила» — скільки правил firewall (filter, nat, mangle, raw) посилаються на список: портал має працювати зі списком, який використовується в правилі, що дозволяє L2TP/IPsec. Список без адрес — нормально: адреси з’являються після входу користувачів. Вибір застосовується після «Зберегти».</p>
<?php elseif ($f['type'] === 'bool'): ?>
        <input type="hidden" name="<?= $e($k) ?>" value="0">
        <label class="check"><input type="checkbox" id="<?= $e($id) ?>" name="<?= $e($k) ?>" value="1"<?= filter_var($values[$k], FILTER_VALIDATE_BOOL) ? ' checked' : '' ?>> увімкнено</label>
<?php elseif ($f['type'] === 'select'): ?>
        <select id="<?= $e($id) ?>" name="<?= $e($k) ?>">
<?php foreach ($f['options'] ?? [] as $ov => $ol): ?>
            <option value="<?= $e($ov) ?>"<?= $values[$k] === $ov ? ' selected' : '' ?>><?= $e($ol) ?></option>
<?php endforeach ?>
        </select>
<?php elseif ($f['type'] === 'categories'): $picked = array_map('trim', explode(',', $values[$k])); ?>
        <div class="checklist">
<?php foreach ($categories as $cv => $cl): $fixed = in_array($cv, ['admin', 'system'], true); ?>
            <label class="check"><input type="checkbox" name="<?= $e($k) ?>[]" value="<?= $e($cv) ?>"<?= $fixed || in_array($cv, $picked, true) ? ' checked' : '' ?><?= $fixed ? ' disabled' : '' ?>> <?= $e($cl) ?></label>
<?php endforeach ?>
        </div>
<?php elseif ($f['type'] === 'secretarea'): ?>
        <textarea id="<?= $e($id) ?>" name="<?= $e($k) ?>" rows="5" spellcheck="false" autocomplete="off" placeholder="<?= ($secretSet[$k] ?? false) ? 'ключ задано; залиште порожнім, щоб не змінювати' : '-----BEGIN OPENSSH PRIVATE KEY----- …' ?>"></textarea>
<?php if ($secretSet[$k] ?? false): ?>
        <label class="check small"><input type="checkbox" name="<?= $e($k) ?>__clear" value="1"> очистити</label>
<?php endif ?>
<?php elseif ($f['type'] === 'secret'): ?>
        <input id="<?= $e($id) ?>" name="<?= $e($k) ?>" type="password" maxlength="256" autocomplete="new-password" placeholder="<?= ($secretSet[$k] ?? false) ? '•••••••• (задано; залиште порожнім, щоб не змінювати)' : 'не задано' ?>">
<?php if ($secretSet[$k] ?? false): ?>
        <label class="check small"><input type="checkbox" name="<?= $e($k) ?>__clear" value="1"> очистити</label>
<?php endif ?>
<?php else: ?>
        <input id="<?= $e($id) ?>" name="<?= $e($k) ?>" value="<?= $e($values[$k]) ?>" maxlength="1024"<?= $f['type'] === 'int' ? ' inputmode="numeric"' : '' ?>>
<?php endif ?>
<?php if (isset($f['help'])): ?>
        <p class="help"><?= $e($f['help']) ?></p>
<?php endif ?>
<?php if ($sources[$k] === 'panel'): ?>
        <button type="submit" name="action" value="reset:<?= $e($k) ?>" class="link small" formnovalidate>повернути значення з .env</button>
<?php endif ?>
    </div>
<?php endforeach ?>
    <div class="actions">
        <button type="submit" name="action" value="save" class="btn-inline">Зберегти</button>
    </div>
</form>

<?php if ($section === 'mikrotik'): ?>
<section class="box">
    <h2>Перевірка з’єднання</h2>
    <p class="muted small">Підключається до кожного маршрутизатора зі збереженими налаштуваннями і читає address-list. Нічого не змінює.</p>
    <form method="post" action="<?= $e($action) ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button type="submit" name="action" value="test" class="btn-inline secondary">Перевірити MikroTik</button>
    </form>
</section>
<?php elseif ($section === 'ad'): ?>
<section class="box">
    <h2>Перевірка входу</h2>
    <p class="muted small">Вхід тестовим обліковим записом зі збереженими налаштуваннями. Перевіряє з’єднання з контролером домену у вибраному режимі, пароль і членство в групі VPN. Пароль ніде не зберігається.</p>
    <form method="post" action="<?= $e($action) ?>" class="form inline-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input name="test_user" placeholder="user або user@домен" maxlength="64" aria-label="Логін" autocapitalize="none" spellcheck="false">
        <input name="test_password" type="password" placeholder="пароль" maxlength="256" aria-label="Пароль" autocomplete="off">
        <button type="submit" name="action" value="test" class="btn-inline secondary">Перевірити AD</button>
    </form>
</section>
<?php elseif ($section === 'mail'): ?>
<section class="box">
    <h2>Тестовий лист</h2>
    <form method="post" action="<?= $e($action) ?>" class="form inline-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input name="test_to" type="email" placeholder="адреса отримувача" maxlength="254" aria-label="Адреса">
        <button type="submit" name="action" value="test" class="btn-inline secondary">Надіслати</button>
    </form>
</section>
<?php elseif ($section === 'logs'): ?>
<p><a href="<?= $e($base) ?>/admin/logs">Переглянути журнал →</a></p>
<?php elseif ($section === 'backup'): ?>
<p><a href="<?= $e($base) ?>/admin/backups">Список резервних копій →</a></p>
<?php endif ?>
