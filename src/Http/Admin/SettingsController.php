<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use VpnGw\Admin\Diagnostics;
use VpnGw\Config;
use VpnGw\ConfigException;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Settings\SettingsSchema;
use VpnGw\Settings\SettingsStore;

/**
 * Settings sections of the admin panel: MikroTik, mail, AD, logs, backup.
 * A change is validated as a whole configuration (Config::withPanelValues) before it
 * is saved, so the panel cannot store something that would stop the portal.
 */
final class SettingsController extends AdminController
{
    public const MAX_ROUTERS = 8;

    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        AuditLogger $audit,
        private readonly SettingsStore $store,
        private readonly Diagnostics $diagnostics,
    ) {
        parent::__construct($cfg, $session, $view, $clock, $audit);
    }

    public function handle(Request $request, Response $response, string $section): Response
    {
        $schema = SettingsSchema::section($section);
        if ($schema === null) {
            return $response->withStatus(404);
        }
        return $this->guarded($request, $response, function (string $admin) use ($request, $response, $section, $schema): Response {
            if ($request->getMethod() !== 'POST') {
                return $this->render($response, $section);
            }
            $action = $this->field($request, 'action', 32);
            if ($action === 'save') {
                return $this->save($request, $response, $section, $schema['fields'], $admin);
            }
            if ($action === 'test') {
                return $this->test($request, $response, $section, $admin);
            }
            if ($action === 'lists' && $section === 'mikrotik') {
                return $this->lists($request, $response, $admin);
            }
            if ($action === 'delete_router' && $section === 'mikrotik') {
                return $this->deleteRouter($request, $response, $admin);
            }
            if ($action === 'toggle_router' && $section === 'mikrotik') {
                return $this->toggleRouter($request, $response, $admin);
            }
            if (str_starts_with($action, 'reset:')) {
                $key = substr($action, 6);
                if (!in_array($key, array_column($schema['fields'], 'key'), true)) {
                    return $response->withStatus(400);
                }
                try {
                    $this->cfg->withPanelValues([$key => $this->cfg->baseValue($key)]);
                } catch (ConfigException $e) {
                    return $this->render($response, $section, ['error' => "Значення з .env некоректне: {$e->getMessage()}"], 422);
                }
                $this->store->forget($key);
                $this->audit->event('admin_settings_reset', $admin, $this->remote($request), ['section' => $section, 'keys' => $key]);
                $this->flash("$key: повернуто значення з .env.");
                return $this->redirect($response, "/admin/settings/$section");
            }
            return $response->withStatus(400);
        });
    }

    /**
     * @param list<array{key: string, label: string, type: string, help?: string, options?: array<string, string>}> $fields
     */
    private function save(Request $request, Response $response, string $section, array $fields, string $admin): Response
    {
        $body = $this->body($request);
        $changes = [];
        $form = [];
        try {
            foreach ($fields as $f) {
                $key = $f['key'];
                if (in_array($f['type'], ['routers', 'hostkeys', 'routerpasswords'], true) && !isset($body['routers'])) {
                    continue; // routers are edited on their own pages (/admin/routers/…)
                }
                if (($f['hidden'] ?? false) && !array_key_exists($key, $body) && !array_key_exists($key . '__clear', $body)) {
                    continue; // not on the page: keep
                }
                $value = $this->readField($body, $f);
                if ($value === null) {
                    continue; // secret left empty = keep
                }
                $form[$key] = $value;
                if ($value !== $this->cfg->get($key)) {
                    $changes[$key] = $value;
                }
            }
            if ($changes === []) {
                $this->flash('Змін немає.', 'info');
                return $this->redirect($response, "/admin/settings/$section");
            }
            $candidate = $this->cfg->withPanelValues($changes);
            $this->panelChecks($candidate, array_keys($changes));
        } catch (ConfigException $e) {
            return $this->render($response, $section, ['error' => self::explain($e->getMessage()), 'form' => $form], 422);
        }
        $this->store->save($changes, $admin, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->event('admin_settings_saved', $admin, $this->remote($request), [
            'section' => $section, 'keys' => implode(',', array_keys($changes)),
        ]);
        $this->flash('Збережено. Нові значення діють з наступного запиту.');
        return $this->redirect($response, "/admin/settings/$section");
    }

    private function test(Request $request, Response $response, string $section, string $admin): Response
    {
        $results = match ($section) {
            'mikrotik' => $this->diagnostics->routers($admin),
            'ad' => [$this->diagnostics->ldap(\VpnGw\Auth\LdapAuthenticator::normalizeUsername($this->field($request, 'test_user', 128), $this->cfg->ldapDomain()), $this->field($request, 'test_password', 257))],
            'mail' => [$this->diagnostics->mail(trim($this->field($request, 'test_to', 254)), $admin)],
            default => [],
        };
        $this->audit->event('admin_test', $admin, $this->remote($request), [
            'section' => $section, 'result' => implode(',', array_map(static fn ($r) => $r['name'] . ':' . ($r['ok'] ? 'ok' : 'fail'), $results)),
        ]);
        return $this->render($response, $section, ['results' => $results]);
    }

    /**
     * The page of one router (/admin/routers/{name}) or of a new one (/admin/routers/add):
     * all its connection settings. Saving rebuilds ROUTERS, ROUTER_PASSWORDS and
     * MT_SSH_HOSTKEYS from the saved routers with this one replaced (or added), through the
     * same checks as before (address change needs the password again, drops the host key, …).
     */
    public function router(Request $request, Response $response, ?string $name): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response, $name): Response {
            $current = null;
            foreach ($this->cfg->routers() as $r) {
                if ($r['name'] === $name) {
                    $current = $r;
                }
            }
            if ($name !== null && $current === null) {
                $this->flash('Такого маршрутизатора немає.', 'warning');
                return $this->redirect($response, '/admin/settings/mikrotik');
            }
            if ($request->getMethod() !== 'POST') {
                return $this->routerPage($response, $current);
            }
            $action = $this->field($request, 'action', 32);
            $body = $this->body($request);
            $posted = is_array($body['router'] ?? null) ? array_filter($body['router'], 'is_string') : [];
            if ($action === 'lists' && $current !== null) {
                $found = $this->diagnostics->addressLists();
                $this->audit->event('admin_test', $admin, $this->remote($request), [
                    'section' => 'mikrotik_lists', 'result' => $current['name'] . ':' . (in_array($current['name'], $found['routers'], true) ? 'ok' : 'fail'),
                ]);
                $errors = array_values(array_filter($found['errors'], static fn (array $e): bool => $e['name'] === $current['name']));
                return $this->routerPage($response, $current, ['mtLists' => $found, 'results' => $errors === [] ? null : $errors, 'posted' => $posted]);
            }
            if ($action !== 'save') {
                return $response->withStatus(400);
            }
            return $this->saveRouter($request, $response, $admin, $current, $posted);
        });
    }

    /**
     * @param array{name: string, host: string, version: int, port: int, transport: string, user: string, list: string, disabled: bool}|null $current
     * @param array<string, string> $posted
     */
    private function saveRouter(Request $request, Response $response, string $admin, ?array $current, array $posted): Response
    {
        $rows = [];
        foreach ($this->cfg->routers() as $r) {
            $rows[] = $current !== null && $r['name'] === $current['name']
                ? array_merge($posted, ['orig' => $r['name'], 'disabled' => $r['disabled'] ? '1' : ''])
                : ['name' => $r['name'], 'host' => $r['host'], 'version' => (string) $r['version'], 'port' => (string) $r['port'],
                    'transport' => $r['transport'], 'user' => $r['user'], 'list' => $r['list'], 'orig' => $r['name'],
                    'disabled' => $r['disabled'] ? '1' : ''];
        }
        if ($current === null) {
            $rows[] = array_merge($posted, ['orig' => '', 'disabled' => '']);
        }
        $name = trim($posted['name'] ?? '');
        $changes = [];
        try {
            if ($name === '' || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name) !== 1) {
                throw new ConfigException('Назва маршрутизатора: латиниця, цифри, _ - (до 32 символів)');
            }
            $body = ['routers' => $rows];
            foreach (SettingsSchema::section('mikrotik')['fields'] ?? [] as $f) {
                if (!in_array($f['type'], ['routers', 'hostkeys', 'routerpasswords'], true)) {
                    continue;
                }
                $value = (string) $this->readField($body, $f);
                if ($value !== $this->cfg->get($f['key'])) {
                    $changes[$f['key']] = $value;
                }
            }
            if ($changes === []) {
                $this->flash('Змін немає.', 'info');
                return $this->redirect($response, '/admin/settings/mikrotik');
            }
            $candidate = $this->cfg->withPanelValues($changes);
            $this->panelChecks($candidate, array_keys($changes));
        } catch (ConfigException $e) {
            return $this->routerPage($response, $current, ['error' => self::explain($e->getMessage()), 'posted' => $posted], 422);
        }
        $this->store->save($changes, $admin, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->event('admin_settings_saved', $admin, $this->remote($request), [
            'section' => 'mikrotik', 'router' => $name, 'keys' => implode(',', array_keys($changes)),
        ]);
        $this->flash($current === null ? "Маршрутизатор «{$name}» додано." : "Маршрутизатор «{$name}» збережено.");
        return $this->redirect($response, '/admin/settings/mikrotik');
    }

    /**
     * @param array{name: string, host: string, version: int, port: int, transport: string, user: string, list: string, disabled: bool}|null $current
     * @param array<string, mixed> $vars
     */
    private function routerPage(Response $response, ?array $current, array $vars = [], int $status = 200): Response
    {
        $r = $current ?? ['name' => '', 'host' => '', 'version' => 7, 'port' => 0, 'transport' => 'api', 'user' => '', 'list' => '', 'disabled' => false];
        $posted = is_array($vars['posted'] ?? null) ? $vars['posted'] : [];
        $form = [
            'name' => $posted['name'] ?? $r['name'],
            'host' => $posted['host'] ?? $r['host'],
            'version' => (int) ($posted['version'] ?? $r['version']) === 6 ? 6 : 7,
            'port' => $posted['port'] ?? ($r['port'] === 0 ? '' : (string) $r['port']),
            'transport' => ($posted['transport'] ?? $r['transport']) === 'ssh' ? 'ssh' : 'api',
            'user' => $posted['user'] ?? $r['user'],
            'list' => ($posted['list'] ?? $r['list']) === '__new' ? ($posted['list_new'] ?? '') : ($posted['list'] ?? $r['list']),
        ];
        unset($vars['posted']);
        return $this->page($response, 'admin_router', 'mikrotik', $vars + [
            'isNew' => $current === null,
            'disabled' => $r['disabled'],
            'orig' => $r['name'],
            'router' => $form,
            'hostKey' => $current !== null ? ($this->cfg->sshHostKeys()[$current['name']] ?? null) : null,
            'hasOwnPassword' => $current !== null && in_array($current['name'], $this->cfg->routersWithOwnPassword(), true),
            'commonUsers' => ['api' => $this->cfg->get('MT_USER'), 'ssh' => $this->cfg->get('MT_SSH_USER')],
            'commonPasswordSet' => ['api' => $this->cfg->get('MT_PASS') !== '', 'ssh' => $this->cfg->get('MT_SSH_PASS') !== '' || $this->cfg->get('MT_SSH_KEY') !== ''],
            'defList' => $this->cfg->get('MT_LIST'),
            'mtLists' => $vars['mtLists'] ?? null,
            'title' => ($current === null ? 'Новий маршрутизатор' : 'Маршрутизатор ' . $current['name']) . ' — VPN-GW',
        ], $status);
    }

    /**
     * Deletes one router (the table under the router cards). Its own password and its
     * pinned SSH host key go with it. The last router cannot be deleted: the portal needs
     * at least one. Other unsaved edits on the page are not touched.
     */
    private function deleteRouter(Request $request, Response $response, string $admin): Response
    {
        $name = $this->field($request, 'router', 32);
        $routers = $this->cfg->routers();
        $left = array_values(array_filter($routers, static fn (array $r): bool => $r['name'] !== $name));
        if (count($left) === count($routers)) {
            $this->flash('Такого маршрутизатора вже немає.', 'warning');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        if ($left === []) {
            $this->flash('Не можна видалити єдиний маршрутизатор: портал має працювати хоча б з одним. Спершу додайте інший.', 'error');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        if (array_filter($left, static fn (array $r): bool => !$r['disabled']) === []) {
            $this->flash('Не можна видалити єдиний увімкнений маршрутизатор: портал має працювати хоча б з одним. Спершу увімкніть інший.', 'error');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        $deleted = array_values(array_filter($routers, static fn (array $r): bool => $r['name'] === $name))[0];
        $changes = ['ROUTERS' => json_encode(array_map(self::exportRouter(...), $left), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        if (in_array($name, $this->cfg->routersWithOwnPassword(), true)) {
            $pw = [];
            foreach ($left as $r) {
                if (in_array($r['name'], $this->cfg->routersWithOwnPassword(), true)) {
                    $pw[$r['name']] = $this->cfg->routerCredentials($r)['password'];
                }
            }
            ksort($pw);
            $changes['ROUTER_PASSWORDS'] = $pw === [] ? '{}' : json_encode($pw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $keys = $this->cfg->sshHostKeys();
        if (isset($keys[$name])) {
            unset($keys[$name]);
            $changes['MT_SSH_HOSTKEYS'] = $keys === [] ? '{}' : json_encode($keys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        try {
            $this->cfg->withPanelValues($changes);
        } catch (ConfigException $e) {
            $this->flash('Не видалено: ' . self::explain($e->getMessage()), 'error');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        $this->store->save($changes, $admin, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->event('admin_router_deleted', $admin, $this->remote($request), [
            'router' => $name, 'host' => $deleted['host'], 'keys' => implode(',', array_keys($changes)),
        ]);
        $this->flash("Маршрутизатор «{$name}» ({$deleted['host']}) видалено. Адреси, які портал уже додав на ньому, MikroTik видалить сам після тайм-ауту.");
        return $this->redirect($response, '/admin/settings/mikrotik');
    }

    /**
     * A router as it is stored in ROUTERS (the form the panel saves).
     *
     * @param array{name: string, host: string, version: int, port: int, transport: string, user: string, list: string, disabled: bool} $r
     * @return array<string, bool|int|string>
     */
    private static function exportRouter(array $r): array
    {
        $out = ['name' => $r['name'], 'host' => $r['host'], 'version' => $r['version'], 'port' => $r['port'], 'transport' => $r['transport']];
        if ($r['user'] !== '') {
            $out['user'] = $r['user'];
        }
        if ($r['list'] !== '') {
            $out['list'] = $r['list'];
        }
        if ($r['disabled']) {
            $out['disabled'] = true;
        }
        return $out;
    }

    /**
     * «Відключити» / «Увімкнути» on a router card: marks the router "disabled" in ROUTERS or
     * clears the mark. Nothing else changes — password, address-list and SSH host key stay, so
     * turning it back on needs no re-entry. The last enabled router cannot be disabled.
     */
    private function toggleRouter(Request $request, Response $response, string $admin): Response
    {
        $name = $this->field($request, 'router', 32);
        $target = null;
        foreach ($this->cfg->routers() as $r) {
            if ($r['name'] === $name) {
                $target = $r;
            }
        }
        if ($target === null) {
            $this->flash('Такого маршрутизатора вже немає.', 'warning');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        $disable = !$target['disabled'];
        $routers = array_map(static fn (array $r): array => $r['name'] === $name ? ['disabled' => $disable] + $r : $r, $this->cfg->routers());
        if (array_filter($routers, static fn (array $r): bool => !$r['disabled']) === []) {
            $this->flash('Не можна відключити єдиний увімкнений маршрутизатор: портал має працювати хоча б з одним.', 'error');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        $changes = ['ROUTERS' => json_encode(array_map(self::exportRouter(...), $routers), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        try {
            $this->cfg->withPanelValues($changes);
        } catch (ConfigException $e) {
            $this->flash('Не змінено: ' . self::explain($e->getMessage()), 'error');
            return $this->redirect($response, '/admin/settings/mikrotik');
        }
        $this->store->save($changes, $admin, $this->clock->now()->format('Y-m-d H:i:s'));
        $host = $target['host'];
        $this->audit->event($disable ? 'admin_router_disabled' : 'admin_router_enabled', $admin, $this->remote($request), [
            'router' => $name, 'host' => $host,
        ]);
        $this->flash($disable
            ? "Маршрутизатор «{$name}» ({$host}) відключено: портал тимчасово не додає на ньому адрес і не розриває сесій; усі налаштування збережено. Адреси, які портал уже додав, MikroTik видалить сам після тайм-ауту."
            : "Маршрутизатор «{$name}» ({$host}) увімкнено: портал знову працює з ним.");
        return $this->redirect($response, '/admin/settings/mikrotik');
    }

    /**
     * Reads the address lists from the routers and shows MT_LIST as a choice. Values typed
     * in the other simple fields are kept on the page (not saved).
     */
    private function lists(Request $request, Response $response, string $admin): Response
    {
        $found = $this->diagnostics->addressLists();
        $this->audit->event('admin_test', $admin, $this->remote($request), [
            'section' => 'mikrotik_lists', 'result' => implode(',', [...array_map(static fn ($r) => "$r:ok", $found['routers']),
                ...array_map(static fn ($e) => $e['name'] . ':fail', $found['errors'])]),
        ]);
        $form = [];
        foreach ($this->body($request) as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== 'MT_LIST' && in_array($k, SettingsSchema::editableKeys(), true) && !SettingsSchema::isSecret($k)) {
                $form[$k] = mb_substr($v, 0, 1024);
            }
        }
        return $this->render($response, 'mikrotik', ['mtLists' => $found, 'form' => $form, 'results' => $found['errors'] === [] ? null : $found['errors']]);
    }

    /**
     * Value of one form field as a config string; null = keep the stored secret.
     *
     * @param array<string, mixed> $body
     * @param array{key: string, label: string, type: string, help?: string, options?: array<string, string>} $f
     */
    private function readField(array $body, array $f): ?string
    {
        $key = $f['key'];
        $raw = $body[$key] ?? null;
        $str = is_string($raw) ? trim(mb_substr($raw, 0, 2048)) : '';
        switch ($f['type']) {
            case 'bool':
                return is_string($raw) && $raw === '1' ? 'true' : 'false';
            case 'select':
                if (!array_key_exists($str, $f['options'] ?? [])) {
                    throw new ConfigException("$key: недопустиме значення");
                }
                return $str;
            case 'secret':
                if (($body[$key . '__clear'] ?? null) === '1') {
                    return '';
                }
                $plain = is_string($raw) ? mb_substr($raw, 0, 256) : '';
                return $plain === '' ? null : $plain;
            case 'secretarea':
                if (($body[$key . '__clear'] ?? null) === '1') {
                    return '';
                }
                $text = is_string($raw) ? trim(str_replace("\r\n", "\n", mb_substr($raw, 0, 16384))) : '';
                return $text === '' ? null : $text . "\n";
            case 'hostkeys':
                return $this->readHostKeys($body);
            case 'routerpasswords':
                return $this->readRouterPasswords($body);
            case 'mtlist':
                if ($str === '__new') {
                    $new = $body[$key . '__new'] ?? null;
                    return is_string($new) ? trim(mb_substr($new, 0, 128)) : '';
                }
                return $str;
            case 'categories':
                $picked = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
                $valid = array_values(array_intersect(array_keys(SettingsSchema::LOG_CATEGORIES), [...$picked, 'admin', 'system']));
                return implode(',', $valid);
            case 'routers':
                return $this->readRouters($body);
            default:
                return $str;
        }
    }

    /** @param array<string, mixed> $body */
    private function readRouters(array $body): string
    {
        $rows = is_array($body['routers'] ?? null) ? $body['routers'] : [];
        $out = [];
        foreach (array_slice($rows, 0, self::MAX_ROUTERS + 2) as $row) {
            if (!is_array($row) || ($row['delete'] ?? null) === '1') {
                continue;
            }
            $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
            $host = trim(is_string($row['host'] ?? null) ? $row['host'] : '');
            if ($name === '' && $host === '') {
                continue;
            }
            $port = trim(is_string($row['port'] ?? null) ? $row['port'] : '');
            $version = ($row['version'] ?? '') === '6' ? 6 : 7;
            $transport = ($row['transport'] ?? '') === 'ssh' ? 'ssh' : 'api';
            $user = trim(is_string($row['user'] ?? null) ? $row['user'] : '');
            $list = trim(is_string($row['list'] ?? null) ? $row['list'] : '');
            if ($list === '__new') {
                $list = trim(is_string($row['list_new'] ?? null) ? $row['list_new'] : '');
            }
            if ($list !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $list)) {
                throw new ConfigException("Маршрутизатор «{$name}»: address-list — лише латиниця, цифри, _ . - (до 64 символів)");
            }
            if ($user !== '' && !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $user)) {
                throw new ConfigException("Маршрутизатор «{$name}»: користувач — лише латиниця, цифри, . _ @ - (до 64 символів)");
            }
            if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
                throw new ConfigException("Маршрутизатор «{$name}»: адреса має бути IP або DNS-ім’ям");
            }
            if (!ctype_digit($port)) {
                throw new ConfigException("Маршрутизатор «{$name}»: порт має бути числом");
            }
            $r = ['name' => $name, 'host' => $host, 'version' => $version, 'port' => (int) $port, 'transport' => $transport];
            if ($user !== '') {
                $r['user'] = $user;
            }
            if ($list !== '') {
                $r['list'] = $list;
            }
            if (($row['disabled'] ?? null) === '1') {
                $r['disabled'] = true;
            }
            $out[] = $r;
        }
        if (count($out) > self::MAX_ROUTERS) {
            throw new ConfigException('Не більше ' . self::MAX_ROUTERS . ' маршрутизаторів');
        }
        if (count(array_unique(array_column($out, 'name'))) !== count($out)) {
            throw new ConfigException('Назви маршрутизаторів мають бути унікальними');
        }
        if ($out !== [] && array_filter($out, static fn (array $r): bool => !isset($r['disabled'])) === []) {
            throw new ConfigException('Має залишитися хоча б один увімкнений маршрутизатор');
        }
        return json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Pinned SSH host keys after this save: a key is kept only while its router keeps the
     * same name, address and port, stays on SSH and the «скинути ключ» box is not ticked.
     * New keys are added only by the «Перевірити MikroTik» button.
     *
     * @param array<string, mixed> $body
     */
    private function readHostKeys(array $body): string
    {
        $old = [];
        foreach ($this->cfg->routers() as $r) {
            $old[$r['name']] = $r;
        }
        $reset = [];
        foreach (is_array($body['routers'] ?? null) ? $body['routers'] : [] as $row) {
            if (is_array($row) && ($row['reset_key'] ?? null) === '1' && is_string($row['name'] ?? null)) {
                $reset[] = trim($row['name']);
            }
        }
        $new = [];
        foreach ((array) json_decode($this->readRouters($body), true) as $r) {
            if (is_array($r) && is_string($r['name'] ?? null)) {
                $new[$r['name']] = $r;
            }
        }
        $keep = [];
        foreach ($this->cfg->sshHostKeys() as $name => $fp) {
            $n = $new[$name] ?? null;
            $o = $old[$name] ?? null;
            if ($n !== null && $o !== null && !in_array($name, $reset, true) && $n['transport'] === 'ssh'
                && $n['host'] === $o['host'] && $n['port'] === $o['port']) {
                $keep[$name] = $fp;
            }
        }
        return $keep === [] ? '{}' : json_encode($keep, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Per-router passwords after this save (write-only: never sent to the browser).
     * A typed password replaces the stored one, «очистити» removes it (the router then uses
     * the common password of its protocol). A stored password follows its row (hidden
     * «orig» name, so renaming keeps it) but is not reused when the router's address or
     * port changes: the administrator has to type it again, so a typo in the address does
     * not send the password to another host.
     *
     * @param array<string, mixed> $body
     */
    private function readRouterPasswords(array $body): string
    {
        $old = [];
        foreach ($this->cfg->routers() as $r) {
            $old[$r['name']] = $r;
        }
        $stored = [];
        foreach ($this->cfg->routersWithOwnPassword() as $n) {
            $stored[$n] = $this->cfg->routerCredentials($old[$n])['password'];
        }
        $out = [];
        foreach (array_slice(is_array($body['routers'] ?? null) ? $body['routers'] : [], 0, self::MAX_ROUTERS + 2) as $row) {
            if (!is_array($row) || ($row['delete'] ?? null) === '1') {
                continue;
            }
            $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
            $host = trim(is_string($row['host'] ?? null) ? $row['host'] : '');
            $port = trim(is_string($row['port'] ?? null) ? $row['port'] : '');
            if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name)) {
                continue; // readRouters reports a bad row
            }
            $typed = is_string($row['password'] ?? null) ? mb_substr($row['password'], 0, 256) : '';
            if (($row['clear_password'] ?? null) === '1') {
                continue;
            }
            if ($typed !== '') {
                $out[$name] = $typed;
                continue;
            }
            $orig = is_string($row['orig'] ?? null) ? $row['orig'] : '';
            if (!isset($stored[$orig], $old[$orig])) {
                continue;
            }
            if ($old[$orig]['host'] !== $host || (string) $old[$orig]['port'] !== $port) {
                throw new ConfigException("Маршрутизатор «{$name}»: адресу або порт змінено — введіть його пароль ще раз (або позначте «типовий»)");
            }
            $out[$name] = $stored[$orig];
        }
        ksort($out);
        return $out === [] ? '{}' : json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Checks that only make sense for values entered in the panel (paths on this server).
     *
     * @param list<string> $changed
     */
    private function panelChecks(Config $c, array $changed): void
    {
        foreach (['MT_CA_FILE', 'LDAP_CA_FILE', 'SMTP_CA_FILE'] as $k) {
            $v = $c->get($k);
            if (in_array($k, $changed, true) && $v !== '') {
                if (!str_starts_with($v, '/etc/ssl/') || str_contains($v, '..')) {
                    throw new ConfigException("$k: файл сертифіката має лежати в /etc/ssl/");
                }
                if (!is_file($v) || !is_readable($v)) {
                    throw new ConfigException("$k: файл $v не знайдено або він недоступний для читання");
                }
            }
        }
        if (in_array('LOG_PATH', $changed, true)) {
            $path = $c->get('LOG_PATH');
            $dirs = [rtrim($c->get('DATA_DIR'), '/') . '/logs', '/var/log/vpn-gw'];
            if (!in_array(dirname($path), $dirs, true) || !preg_match('/^[A-Za-z0-9._-]+\.log$/', basename($path))) {
                throw new ConfigException('LOG_PATH: файл *.log у ' . implode(' або ', $dirs));
            }
            if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
                throw new ConfigException('LOG_PATH: каталог ' . dirname($path) . ' не існує або недоступний для запису (запустіть install.sh)');
            }
        }
        if ((in_array('MT_SSH_KEY', $changed, true) || in_array('MT_SSH_KEY_PASS', $changed, true)) && $c->get('MT_SSH_KEY') !== '') {
            try {
                $pass = $c->get('MT_SSH_KEY_PASS');
                $key = $pass === ''
                    ? \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($c->get('MT_SSH_KEY'))
                    : \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($c->get('MT_SSH_KEY'), $pass);
            } catch (\Throwable) {
                $key = null;
            }
            if (!$key instanceof \phpseclib3\Crypt\Common\PrivateKey) {
                throw new ConfigException('SSH: приватний ключ не читається (формат або пароль ключа)');
            }
        }
        if ($c->bool('BACKUP_ENABLED') && $c->get('BACKUP_PASSPHRASE') === '') {
            throw new ConfigException('Для автоматичного копіювання задайте пароль шифрування копій');
        }
    }

    /** Human-readable Ukrainian text for the most common validation messages. */
    private static function explain(string $msg): string
    {
        $router = preg_match('/: ([A-Za-z0-9_-]{1,32})(?: has no own user)?$/', $msg, $m) === 1
            && (str_starts_with($msg, 'Missing required setting MT_USER') || str_starts_with($msg, 'MT_SSH_USER') || str_starts_with($msg, 'SSH routers need'))
            ? " (маршрутизатор «{$m[1]}»)" : '';
        $map = [
            'ROUTERS[' => 'Маршрутизатори: потрібні назва (латиниця, цифри, _ -), адреса, версія 6 або 7, протокол і порт 1–65535',
            'Missing required setting MT_USER' => 'Для маршрутизатора з протоколом API задайте власного користувача або «API: типовий користувач»',
            'Missing required setting MT_CA_FILE' => 'Для маршрутизаторів з протоколом API вкажіть «API: сертифікат CA»',
            'MT_SSH_USER is required' => 'Для маршрутизатора з протоколом SSH задайте власного користувача або «SSH: типовий користувач»',
            'SSH routers need' => 'Для SSH-маршрутизатора задайте власний пароль, «SSH: типовий пароль» або приватний ключ',
            'ROUTER_PASSWORDS' => 'Паролі маршрутизаторів: некоректне значення',
            'MT_SSH_KEY must be' => 'SSH: вставте приватний ключ повністю (рядки BEGIN … PRIVATE KEY … END)',
            'Missing required setting LDAP_CA_FILE' => 'У режимі з перевіркою сертифіката потрібен «Сертифікат CA»',
            'LDAP_SECURITY' => 'Недопустимий режим підключення LDAP',
            'ROUTERS must be a non-empty' => 'Додайте хоча б один маршрутизатор',
            'Missing required setting' => 'Заповніть обов’язкове поле: ' . substr($msg, strlen('Missing required setting ')),
            'LDAP_HOSTS:' => 'Контролери домену: для режимів LDAPS — адреси ldaps://, для StartTLS і без шифрування — ldap://',
            'MT_TIMEOUT' => 'Тривалість доступу: формат ГГ:ХХ:СС, наприклад 10:00:00',
            'PRIVATE_CLIENT_NETWORKS:' => 'Приватні мережі: CIDR через кому, наприклад 172.20.2.0/23 (не ширше /8)',
            'MT_LIST' => 'Address-list: лише латиниця, цифри, _ . -',
            'LDAP_DOMAIN is required' => 'Вкажіть домен, наприклад nerc.gov.ua',
            'LDAP_DOMAIN must' => 'Домен має виглядати як nerc.gov.ua',
            'LDAP_VPN_GROUP is required' => 'Вкажіть назву групи VPN, наприклад VPN-Users',
            'LDAP_VPN_GROUP:' => 'Назва групи: літери, цифри, пробіл, _ . -',
            'SMTP_FROM' => 'Адреса відправника має бути e-mail',
            'SMTP_HOST' => 'SMTP-сервер: ім’я хоста або IP-адреса',
            'LOG_REMOTE' => 'Віддалений syslog: хост:порт, наприклад siem.company.local:514',
            'BACKUP_PASSPHRASE' => 'Пароль шифрування копій — мінімум 12 символів',
        ];
        foreach ($map as $prefix => $text) {
            if (str_starts_with($msg, $prefix)) {
                return $text . $router;
            }
        }
        return $msg;
    }

    /** @param array<string, mixed> $vars */
    private function render(Response $response, string $section, array $vars = [], int $status = 200): Response
    {
        $schema = SettingsSchema::section($section) ?? throw new \LogicException('unknown section');
        $values = [];
        $sources = [];
        foreach ($schema['fields'] as $f) {
            $k = $f['key'];
            $values[$k] = SettingsSchema::isSecret($k) ? '' : $this->cfg->get($k);
            $sources[$k] = $this->cfg->source($k);
        }
        $form = is_array($vars['form'] ?? null) ? $vars['form'] : [];
        foreach ($form as $k => $v) {
            if (is_string($k) && is_string($v) && !SettingsSchema::isSecret($k)) {
                $values[$k] = $v;
            }
        }
        $routers = json_decode($values['ROUTERS'] ?? '[]', true);
        return $this->page($response, 'admin_settings', $section === 'logs' ? 'logs_settings' : $section, $vars + [
            'section' => $section,
            'schema' => $schema,
            'values' => $values,
            'sources' => $sources,
            'secretSet' => array_combine(SettingsSchema::SECRETS, array_map(fn ($k) => $this->cfg->get($k) !== '', SettingsSchema::SECRETS)),
            'routers' => is_array($routers) ? $routers : [],
            'savedRouters' => $this->cfg->routers(),
            'categories' => SettingsSchema::LOG_CATEGORIES,
            'meta' => $this->store->meta(),
            'hostKeys' => $this->cfg->sshHostKeys(),
            'ownPasswords' => $this->cfg->routersWithOwnPassword(),
            'commonUsers' => ['api' => $this->cfg->get('MT_USER'), 'ssh' => $this->cfg->get('MT_SSH_USER')],
        ], $status);
    }
}
