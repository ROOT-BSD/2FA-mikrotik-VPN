<?php

declare(strict_types=1);

namespace VpnGw\Settings;

/**
 * Which settings the admin panel may change, how they are grouped and shown.
 * Everything else (keys, base path, internal networks, file locations of the DB)
 * stays in .env only: changing those from the web could lock the administrator out
 * or let a web compromise disable protections.
 *
 * @phpstan-type Field array{key: string, label: string, type: string, help?: string, group?: string, options?: array<string, string>, hidden?: bool}
 * @phpstan-type Section array{title: string, intro: string, fields: list<Field>}
 */
final class SettingsSchema
{
    /** Write-only values: stored encrypted with APP_KEY and never sent back to the browser. */
    public const SECRETS = ['MT_PASS', 'SMTP_PASS', 'BACKUP_PASSPHRASE', 'MT_SSH_PASS', 'MT_SSH_KEY', 'MT_SSH_KEY_PASS', 'ROUTER_PASSWORDS'];

    /**
     * Router connection parameters that are shown in the panel but also stored encrypted
     * (addresses, logins, pinned SSH host keys).
     */
    public const ENCRYPTED = ['ROUTERS', 'MT_USER', 'MT_SSH_USER', 'MT_SSH_HOSTKEYS'];

    /** Audit-log categories; "admin" and "system" are always written. */
    public const LOG_CATEGORIES = [
        'auth' => 'Вхід: пароль AD, другий фактор, блокування',
        'access' => 'Доступ: додавання/видалення IP на MikroTik',
        'enroll' => 'Реєстрація і скидання автентифікатора',
        'mail' => 'Надсилання листів',
        'admin' => 'Дії адміністраторів (завжди)',
        'system' => 'Помилки, резервні копії, оновлення (завжди)',
    ];

    /** @var array<string, Section> */
    public const SECTIONS = [
        'mikrotik' => [
            'title' => 'Підключення до MikroTik',
            'intro' => 'Картка на кожен маршрутизатор: назва, адреса, версія RouterOS і протокол (API — REST / API-SSL з сертифікатом корпоративного CA, або SSH з перевіркою ключа хоста). Параметри підключення змінюються на сторінці маршрутизатора — кнопка «Редагувати». Параметри підключення зберігаються зашифрованими.',
            'fields' => [
                ['key' => 'ROUTERS', 'label' => 'Маршрутизатори', 'type' => 'routers'],
                ['key' => 'MT_SSH_HOSTKEYS', 'label' => 'Ключі SSH маршрутизаторів', 'type' => 'hostkeys'],
                ['key' => 'ROUTER_PASSWORDS', 'label' => 'Паролі маршрутизаторів', 'type' => 'routerpasswords'],
                ['key' => 'MT_LIST', 'label' => 'Типовий address-list', 'type' => 'mtlist', 'hidden' => true],
                ['key' => 'MT_TIMEOUT', 'label' => 'Тривалість доступу (ГГ:ХХ:СС)', 'type' => 'text', 'help' => 'Після цього часу MikroTik сам видаляє IP зі списку.'],
                ['key' => 'PRIVATE_CLIENT_NETWORKS', 'label' => 'Дозволені приватні мережі клієнтів', 'type' => 'text', 'help' => 'Через кому, наприклад 172.20.2.0/23. Звичайно портал приймає лише публічні IP; з цих мереж вхід теж дозволено (тестування з офісу, клієнти за внутрішнім каналом). Порожньо — лише публічні IP.'],
                ['key' => 'MT_CA_FILE', 'label' => 'Сертифікат CA для протоколу API (файл PEM)', 'type' => 'text', 'hidden' => true],
                // MT_LIST, MT_CA_FILE and the common credentials of each protocol: not shown on the page any more (each router has
                // its own on its edit page), but still read from .env or from an earlier panel save, and
                // used for a router without its own user / password. 'hidden' fields are never changed
                // by a save that does not send them.
                ['key' => 'MT_USER', 'label' => 'API: типовий користувач', 'type' => 'text', 'hidden' => true],
                ['key' => 'MT_PASS', 'label' => 'API: типовий пароль', 'type' => 'secret', 'hidden' => true],
                ['key' => 'MT_SSH_USER', 'label' => 'SSH: типовий користувач', 'type' => 'text', 'hidden' => true],
                ['key' => 'MT_SSH_PASS', 'label' => 'SSH: типовий пароль', 'type' => 'secret', 'hidden' => true],
                ['key' => 'MT_SSH_KEY', 'label' => 'SSH: приватний ключ', 'type' => 'secretarea', 'hidden' => true],
                ['key' => 'MT_SSH_KEY_PASS', 'label' => 'SSH: пароль ключа', 'type' => 'secret', 'hidden' => true],
            ],
        ],
        'mail' => [
            'title' => 'Налаштування пошти',
            'intro' => 'Сповіщення «VPN-доступ відкрито» і резервний код на e-mail. Порожній SMTP-сервер — листи вимкнено.',
            'fields' => [
                ['key' => 'SMTP_HOST', 'label' => 'SMTP-сервер', 'type' => 'text'],
                ['key' => 'SMTP_PORT', 'label' => 'Порт (STARTTLS)', 'type' => 'int'],
                ['key' => 'SMTP_USER', 'label' => 'Користувач SMTP', 'type' => 'text', 'help' => 'Порожньо — без автентифікації.'],
                ['key' => 'SMTP_PASS', 'label' => 'Пароль SMTP', 'type' => 'secret'],
                ['key' => 'SMTP_FROM', 'label' => 'Адреса відправника', 'type' => 'text'],
                ['key' => 'SMTP_CA_FILE', 'label' => 'Сертифікат CA (файл PEM)', 'type' => 'text', 'help' => 'Порожньо — системне сховище сертифікатів.'],
                ['key' => 'EMAIL_OTP_ENABLED', 'label' => 'Дозволити резервний код на e-mail', 'type' => 'bool', 'help' => 'Рекомендовано вимкнути: пошта часто доступна з того ж телефона.'],
                ['key' => 'IT_CONTACT', 'label' => 'Контакт ІТ-служби (у листах і повідомленнях)', 'type' => 'text'],
            ],
        ],
        'ad' => [
            'title' => 'Підключення до Active Directory',
            'intro' => 'Рекомендовано LDAPS з перевіркою сертифіката. Режими без перевірки сертифіката не захищають від підміни контролера домену; без шифрування пароль передається відкритим текстом.',
            'fields' => [
                ['key' => 'LDAP_SECURITY', 'label' => 'Режим підключення', 'type' => 'select', 'options' => [
                    'ldaps' => 'LDAPS (636) з перевіркою сертифіката — рекомендовано',
                    'ldaps-insecure' => 'LDAPS (636) без перевірки сертифіката',
                    'starttls' => 'LDAP + StartTLS (389) з перевіркою сертифіката',
                    'starttls-insecure' => 'LDAP + StartTLS (389) без перевірки сертифіката',
                    'plain' => 'LDAP (389) без шифрування — не рекомендовано',
                ]],
                ['key' => 'LDAP_HOSTS', 'label' => 'Контролери домену', 'type' => 'text', 'help' => 'Через кому. LDAPS: ldaps://dc01.company.local:636,ldaps://dc02.company.local:636; StartTLS і без шифрування: ldap://dc01.company.local:389'],
                ['key' => 'LDAP_DOMAIN', 'label' => 'Домен', 'type' => 'text', 'help' => 'Наприклад, nerc.gov.ua. Співробітники входять як user або user@nerc.gov.ua.'],
                ['key' => 'LDAP_VPN_GROUP', 'label' => 'Група VPN', 'type' => 'text', 'help' => 'Назва групи в AD, наприклад VPN-Users. Враховуються і вкладені групи.'],
                ['key' => 'LDAP_CA_FILE', 'label' => 'Сертифікат CA (файл PEM)', 'type' => 'text', 'help' => 'Потрібен лише в режимах з перевіркою сертифіката.'],
            ],
        ],
        'logs' => [
            'title' => 'Журнали',
            'intro' => 'Журнал аудиту — один JSON-рядок на подію. Паролі, коди й токени не записуються ніколи.',
            'fields' => [
                ['key' => 'LOG_PATH', 'label' => 'Файл журналу', 'type' => 'text', 'help' => 'У каталозі /var/lib/vpn-gw/logs/ або /var/log/vpn-gw/, ім’я *.log'],
                ['key' => 'LOG_RETENTION_DAYS', 'label' => 'Зберігати, днів', 'type' => 'int', 'help' => 'Щоденна ротація зі стисненням; старіші файли видаляються.'],
                ['key' => 'LOG_LEVEL', 'label' => 'Рівень', 'type' => 'select', 'options' => ['info' => 'Усі події (info)', 'warning' => 'Лише невдачі та помилки (warning)']],
                ['key' => 'LOG_EVENTS', 'label' => 'Що записувати', 'type' => 'categories'],
                ['key' => 'LOG_SYSLOG', 'label' => 'Дублювати в локальний syslog', 'type' => 'bool'],
                ['key' => 'LOG_SYSLOG_FACILITY', 'label' => 'Facility syslog', 'type' => 'select', 'options' => [
                    'local0' => 'local0', 'local1' => 'local1', 'local2' => 'local2', 'local3' => 'local3',
                    'local4' => 'local4', 'local5' => 'local5', 'local6' => 'local6', 'local7' => 'local7',
                ]],
                ['key' => 'LOG_REMOTE', 'label' => 'Віддалений syslog / SIEM (UDP)', 'type' => 'text', 'help' => 'хост:порт, наприклад siem.company.local:514. Порожньо — не надсилати.'],
            ],
        ],
        'backup' => [
            'title' => 'Резервне копіювання',
            'intro' => 'Копія містить базу даних, .env, сертифікати й конфігурацію Apache. Архів шифрується паролем (Argon2id + XChaCha20-Poly1305).',
            'fields' => [
                ['key' => 'BACKUP_ENABLED', 'label' => 'Щоденне автоматичне копіювання', 'type' => 'bool'],
                ['key' => 'BACKUP_HOUR', 'label' => 'Година запуску (0–23)', 'type' => 'int'],
                ['key' => 'BACKUP_KEEP', 'label' => 'Зберігати копій', 'type' => 'int'],
                ['key' => 'BACKUP_PASSPHRASE', 'label' => 'Пароль шифрування копій', 'type' => 'secret', 'help' => 'Мінімум 12 символів. Збережіть у сейфі паролів: без нього копію не відновити.'],
            ],
        ],
    ];

    /** @return list<string> */
    public static function editableKeys(): array
    {
        $keys = [];
        foreach (self::SECTIONS as $section) {
            foreach ($section['fields'] as $f) {
                $keys[] = $f['key'];
            }
        }
        return $keys;
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRETS, true);
    }

    /** Stored encrypted in the database (secrets and router connection parameters). */
    public static function isEncrypted(string $key): bool
    {
        return self::isSecret($key) || in_array($key, self::ENCRYPTED, true);
    }

    /** @return Section|null */
    public static function section(string $name): ?array
    {
        return self::SECTIONS[$name] ?? null;
    }

    /** Category of an audit event (see LOG_CATEGORIES). */
    public static function eventCategory(string $event): string
    {
        return match (true) {
            str_starts_with($event, 'admin_') => 'admin',
            in_array($event, ['login_ok', 'login_fail', 'login_blocked', 'mfa_ok', 'mfa_fail', 'mfa_not_enrolled',
                'lockout', 'unlock', 'email_otp_sent', 'email_otp_limited'], true) => 'auth',
            in_array($event, ['mt_add', 'mt_update', 'mt_revoke', 'revoke_self'], true) => 'access',
            in_array($event, ['enroll_ok', 'enroll_fail', 'enroll_email', 'totp_reset'], true) => 'enroll',
            in_array($event, ['mail_sent', 'mail_skipped'], true) => 'mail',
            default => 'system',
        };
    }
}
