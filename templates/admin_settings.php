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
 * @var string|null $error
 * @var list<array{name: string, ok: bool, message: string}>|null $results
 */
$error ??= null;
$results ??= null;
$action = $base . '/admin/settings/' . $section;
$srcLabel = ['panel' => 'змінено в панелі', 'env' => 'з .env', 'default' => 'типове'];
$rows = $routers;
for ($i = count($rows); $i < count($routers) + 2; $i++) {
    $rows[] = ['name' => '', 'host' => '', 'version' => 7, 'port' => ''];
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
    <div class="field">
        <div class="field-head">
            <label for="<?= $e($id) ?>"><?= $e($f['label']) ?></label>
            <span class="src src-<?= $e($sources[$k]) ?>" title="<?= isset($meta[$k]) ? $e('Змінив ' . $meta[$k]['updated_by'] . ' ' . $meta[$k]['updated_at']) : '' ?>"><code><?= $e($k) ?></code> · <?= $e($srcLabel[$sources[$k]] ?? '') ?></span>
        </div>
<?php if ($f['type'] === 'routers'): ?>
        <table class="table routers">
            <thead><tr><th>Назва</th><th>Адреса (IP / DNS)</th><th>RouterOS</th><th>Порт</th><th>Видалити</th></tr></thead>
            <tbody>
<?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td><input name="routers[<?= $e($i) ?>][name]" value="<?= $e($r['name'] ?? '') ?>" maxlength="32" pattern="[A-Za-z0-9_\-]*" aria-label="Назва"></td>
                    <td><input name="routers[<?= $e($i) ?>][host]" value="<?= $e($r['host'] ?? '') ?>" maxlength="253" aria-label="Адреса"></td>
                    <td><select name="routers[<?= $e($i) ?>][version]" aria-label="Версія">
                        <option value="7"<?= ($r['version'] ?? 7) === 7 ? ' selected' : '' ?>>v7 REST</option>
                        <option value="6"<?= ($r['version'] ?? 7) === 6 ? ' selected' : '' ?>>v6 API-SSL</option>
                    </select></td>
                    <td><input name="routers[<?= $e($i) ?>][port]" value="<?= $e($r['port'] ?? '') ?>" maxlength="5" inputmode="numeric" class="w-port" aria-label="Порт" placeholder="<?= ($r['name'] ?? '') === '' ? '443' : '' ?>"></td>
                    <td class="center"><?php if (($r['name'] ?? '') !== ''): ?><input type="checkbox" name="routers[<?= $e($i) ?>][delete]" value="1" aria-label="Видалити"><?php endif ?></td>
                </tr>
<?php endforeach ?>
            </tbody>
        </table>
        <p class="help">Порожні рядки ігноруються. Типові порти: REST (www-ssl) — 443, API-SSL — 8729.</p>
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
    <p class="muted small">Вхід тестовим обліковим записом зі збереженими налаштуваннями. Перевіряє LDAPS, сертифікат, пароль і членство в групі VPN. Пароль ніде не зберігається.</p>
    <form method="post" action="<?= $e($action) ?>" class="form inline-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input name="test_user" placeholder="логін" maxlength="64" aria-label="Логін" autocapitalize="none" spellcheck="false">
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
