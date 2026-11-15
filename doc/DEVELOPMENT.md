# VPN-GW: посібник розробника

**Версія документа 4.0 · 15.11.2026 · відповідає коду VPN-GW 0.2** · Супровідні документи: `INSTALL_GUIDE.md` (встановлення), `ADMIN_GUIDE.md` (панель адміністратора), `USER_GUIDE.md` (для співробітників), `VPN-GW_Як_це_працює.docx` (огляд), `CHANGELOG.md`

Документ описує архітектуру і внутрішню будову реалізованого порталу: де що лежить, як працює кожен модуль, які рішення безпеки закладено і як вносити зміни. Код — у репозиторії; тут лише ключові фрагменти.

---

## 0. Коротко

Портал відкриває VPN-порти (L2TP/IPsec) на MikroTik **тільки для IP-адреси користувача**, який:

1. пройшов перевірку логіна й пароля в Active Directory (LDAPS, група `VPN-Users`, з урахуванням вкладених груп);
2. ввів код Microsoft Authenticator (TOTP; резервно — код на пошту з AD, вимкнено за замовчуванням);
3. звертається з валідної публічної IPv4.

IP додається у `/ip firewall address-list` (список `MT_LIST`, timeout `MT_TIMEOUT`, за замовчуванням 10 год) **на всіх** маршрутизаторах з `ROUTERS`: RouterOS 7 — через REST API, RouterOS 6 — через API-SSL.

---

## 1. Стек і залежності

| Компонент | Версія / пакет |
|---|---|
| PHP | 8.4 на Debian 13 (мінімум 8.2); розширення: `ldap curl sodium pdo_sqlite mbstring openssl sockets json` |
| Веб-сервер | Apache 2.4 (`mpm_event`, `mod_proxy_fcgi`) + PHP-FPM; **без** `mod_php` |
| Мікрофреймворк | `slim/slim` ^4.12, `slim/psr7` ^1.6 |
| TOTP | `spomky-labs/otphp` ^11.2, `paragonie/constant_time_encoding`, `psr/clock` |
| QR-код | `endroid/qr-code` ^5.1 (SVG, data-URI) |
| RouterOS 6 | `evilfreelancer/routeros-api-php` ^1.5 (потрібен `ext-sockets`) |
| RouterOS 7 | `ext-curl` (REST) |
| Пошта | `phpmailer/phpmailer` ^6.9 |
| Конфігурація / журнал | `vlucas/phpdotenv` ^5.6, `monolog/monolog` ^3 |
| Frontend | HTML-шаблони PHP + власний CSS (`public/assets/app.css`), **без JS і без збірки** |
| Сховище | SQLite (WAL) |
| Розробка | `phpunit/phpunit` ^11, `phpstan/phpstan` ^2 (рівень 8) |

---

## 2. Структура проєкту

```
VERSION                    номер версії (єдине джерело); див. розділ 11
install.sh                 встановлення на Debian з перевіркою залежностей (INSTALL_GUIDE)
public/
  index.php                front controller: Config::load -> сесія -> App
  assets/app.css           стилі
src/
  Config.php               .env + значення з панелі, валідація (fail closed), ConfigException.php
  Container.php            сервіси (lazy, перевизначення в тестах)
  App.php                  маршрути Slim, порядок middleware, обробник помилок
  Version.php              Version::CURRENT
  Auth/                    Authenticator (інтерфейс), LdapAuthenticator, AuthResult, TotpService, EmailOtp
  Security/                ClientIp, Cidr, Crypto, RateLimiter
  RouterOs/                RouterOsClient (інтерфейс), RestClientV7, ApiSslClientV6,
                           RouterOsFactory, AccessGranter, RouterOsException
  Http/                    Controller (база), LoginController, MfaController, ResultController,
                           EnrollController, Session, View
  Http/Middleware/         SecurityHeaders, Csrf, InternalNetworkOnly
  Http/Admin/              панель: AdminController (база), AuthController, DashboardController,
                           SettingsController, LogsController, MaintenanceController
  Admin/                   AdminAuth (облікові записи), Jobs (черга агента), LogReader, Diagnostics
  Settings/                SettingsSchema (що редагується в панелі), SettingsStore (БД, шифрування)
  Agent/                   Agent (root), BackupArchive, ReleaseInspector, Proc
  Storage/                 Database, migrations/001_init.sql, 002_admin.sql
  Log/AuditLogger.php      JSON-журнал (файл, syslog, віддалений syslog), категорії подій
  Mail/Notifier.php        листи через SMTP STARTTLS
  Support/SystemClock.php  PSR-20 годинник
templates/                 layout, login, mfa, result, message, enroll_login, enroll_qr, admin_*
bin/                       bootstrap, migrate, cleanup, unlock, totp-reset, mt-check,
                           admin, settings, agent (root), agent-helper, restore (root)
deploy/                    apache, php-fpm, systemd, logrotate, nftables, mikrotik (*.rsc)
tests/                     PHPUnit 11: unit + наскрізні HTTP-сценарії (Support/ — фейки)
doc/                      QUICKSTART.md, INSTALL_GUIDE.md, ADMIN_GUIDE.md, DEVELOPMENT.md, USER_GUIDE.md
```

Webroot — лише `public/`. `src/`, `.env`, `vendor/`, `/var/lib/vpn-gw` ззовні недоступні.

---

## 3. Конфігурація (`.env` + панель)

Повний перелік — `.env.example`. Джерела, від нижчого пріоритету до вищого: `DEFAULTS` у `Config` → `.env` → таблиця `settings` у БД (лише ключі з `SettingsSchema::editableKeys()`). `Config::load()` читає `.env`, потім — якщо файл БД існує — значення панелі через `SettingsStore` (секрети `MT_PASS`, `SMTP_PASS`, `BACKUP_PASSPHRASE` розшифровуються `APP_KEY`; помилка розшифрування — `ConfigException`, а не тихий відкат до `.env`). `Config::fromArray($env, $panel)` перевіряє **об'єднану** конфігурацію і кидає `ConfigException`; `public/index.php` у такому разі віддає порожню 500 і пише причину в `error_log`.

`Config::withPanelValues($changes)` будує нову конфігурацію зі змінами з форми — так панель перевіряє значення **до** збереження. `source($key)` повертає `panel | env | default`, `baseValue($key)` — значення без панелі (кнопка «повернути значення з .env», `bin/settings.php reset`).

Ключі, що впливають на доступ до панелі (`APP_*`, `TRUSTED_PROXIES`, `INTERNAL_NETWORKS`, `RATE_*`, `LOCKOUT_SECONDS`, шляхи), з панелі не змінюються: `fromArray` відкидає їх з `$panel`.

| Ключ | Перевірка |
|---|---|
| `APP_BASE_PATH` | `/[A-Za-z0-9_-]{1,64}` — шлях порталу; має збігатися з віртуальним хостом |
| `APP_KEY`, `APP_HMAC_KEY` | `base64:` + рівно 32 байти |
| `TRUSTED_PROXIES` | список IP (для Apache без балансувальника — `127.0.0.1`) |
| `INTERNAL_NETWORKS` | список IPv4 CIDR, звідки дозволено `/enroll` і `/admin` |
| `LDAP_HOSTS` | лише `ldaps://…`; декілька — через кому (перебираються, якщо DC недоступний) |
| `ROUTERS` | JSON-масив `{name, host, version, port}`, version — 6 або 7; у `.env` — **в одинарних лапках** |
| `MT_LIST` | `[A-Za-z0-9_.-]{1,64}` |
| `MT_TIMEOUT` | `HH:MM:SS` |
| `RATE_*` | `N/секунд`, напр. `5/900` |
| `EMAIL_OTP_ENABLED` | `false` за замовчуванням |
| `SMTP_HOST` | порожній = листи вимкнено (подія `mail_skipped`) |
| `IT_CONTACT` | текст із пробілами — **у подвійних лапках** |
| `DB_PATH`, `LOCK_DIR` | шляхи в `/var/lib/vpn-gw` |
| `LOG_PATH` | абсолютний шлях; панель додатково дозволяє лише `DATA_DIR/logs/*.log` і `/var/log/vpn-gw/*.log` |
| `LOG_LEVEL` / `LOG_EVENTS` | `info` або `warning` / підмножина `auth, access, enroll, mail, admin, system` |
| `LOG_SYSLOG`, `LOG_SYSLOG_FACILITY`, `LOG_REMOTE` | `true/false`, `local0`–`local7`, `хост:порт` (UDP) |
| `LOG_RETENTION_DAYS` | 1–3650 (агент пише в `/etc/logrotate.d/vpn-gw`) |
| `BACKUP_ENABLED`, `BACKUP_HOUR`, `BACKUP_KEEP`, `BACKUP_PASSPHRASE` | `true/false`, 0–23, 1–365, ≥ 12 символів |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_FROM`, `LDAP_UPN_SUFFIX` | ім'я хоста, 1–65535, e-mail, `@домен`; без керувальних символів |
| `DATA_DIR`, `AGENT_DIR`, `BACKUP_DIR` | каталоги застосунку, агента і копій (змінюються разом з `install.sh`) |

---

## 4. HTTP: маршрути, middleware, стани

Усі маршрути — під `APP_BASE_PATH`. Невідомий шлях і невідомий метод (405) → **404** з порожнім тілом.

| Метод | Шлях | Контролер | Дія |
|---|---|---|---|
| GET | `/` | Login::show | форма |
| POST | `/login` | Login::submit | AD → стан `awaiting_2fa` → 303 `/verify` |
| GET | `/verify` | Mfa::show | форма коду |
| POST | `/verify` | Mfa::submit | TOTP/Email OTP → `AccessGranter::grant` → стан `granted` → 303 `/result` |
| POST | `/verify/email` | Mfa::sendEmail | резервний код (ліміт `RATE_EMAIL_OTP`) |
| GET | `/result` | Result::show | «Доступ дозволено до …» |
| POST | `/revoke` | Result::revoke | `AccessGranter::revoke` → знищення сесії |
| GET/POST | `/enroll` | Enroll::handle | реєстрація TOTP (`action=login` / `action=confirm`) |
| GET/POST | `/admin/login`, POST `/admin/logout` | Admin\Auth | вхід адміністратора |
| GET | `/admin` | Admin\Dashboard | огляд |
| GET/POST | `/admin/settings/{mikrotik,mail,ad,logs,backup}` | Admin\Settings | `action=save` / `test` / `reset:KEY` |
| GET | `/admin/logs` | Admin\Logs | перегляд журналу (`q`, `level`, `category`) |
| GET/POST | `/admin/backups`, GET `/admin/backups/{файл}` | Admin\Maintenance | список, «створити зараз», завантаження |
| GET/POST | `/admin/update`, GET `/admin/jobs/{id}` | Admin\Maintenance | завантаження релізу, стан завдання |
| GET/POST | `/admin/password` | Admin\Auth | зміна власного пароля |

**Порядок middleware** (Slim виконує LIFO; у `App::create` додаються у зворотному порядку):

```
ErrorMiddleware → аудит-контекст → SecurityHeaders → Routing (404)
  → InternalNetworkOnly(/enroll, /admin/*) → Csrf → контролер
```

`InternalNetworkOnly` стоїть **перед** CSRF, щоб POST на `/enroll` чи `/admin/*` ззовні отримував 404, а не 403 (інакше видно, що маршрут існує). Шлях порівнюється після `rawurldecode`, як і в маршрутизаторі Slim. Обробник помилок повторно додає заголовки безпеки до 404/500.

**Машина станів сесії** (`Controller::inStage`): стан + час + IP мають збігатися.

```
none ──POST /login (AD ok, TOTP/email доступні)──► awaiting_2fa (TTL 300 с)
awaiting_2fa ──POST /verify (код ok, grant ok)──► granted (TTL 600 с) ──POST /revoke──► destroy
будь-яка невідповідність стану / TTL / IP ──► destroy + 303 на початок
```

Після кожного кроку — `session_regenerate_id(true)` і новий CSRF-токен. Cookie: `__Host-vpngw`, Secure, HttpOnly, SameSite=Strict, без `domain`.

---

## 5. Модулі

### 5.1. `Security\ClientIp`
`X-Forwarded-For` враховується лише якщо `REMOTE_ADDR` ∈ `TRUSTED_PROXIES`, береться крайнє праве значення. Результат — **одна публічна IPv4** (`FILTER_FLAG_IPV4 | NO_PRIV_RANGE | NO_RES_RANGE`), інакше `null` і відмова. Apache додатково видаляє клієнтський XFF (`RequestHeader unset … early`).

### 5.2. `Auth\LdapAuthenticator`
- До будь-якого виклику LDAP: логін `^[A-Za-z0-9._-]{1,64}$`, пароль непорожній і ≤ 256 символів (порожній пароль = анонімний bind, який AD підтверджує).
- Для кожного хоста: `LDAP_OPT_X_TLS_REQUIRE_CERT = DEMAND`, CA з `LDAP_CA_FILE`, таймаути 5 с; `LDAP_OPT_X_TLS_NEWCTX` — якщо константа є (Debian/OpenLDAP).
- Bind `логін@UPN_SUFFIX`; `errno -1` (сервер недоступний) → наступний DC.
- Пошук з фільтром: `sAMAccountName`, `memberOf:1.2.840.113556.1.4.1941:=<група>` (вкладені групи), `!(userAccountControl:1.2.840.113556.1.4.803:=2)` (не вимкнений); перевірка `accountExpires` (FILETIME).
- Повертає `AuthResult` з причиною для журналу (`invalid_input | bad_credentials | not_allowed | expired | ldap_unavailable`); користувач завжди бачить «Невірні облікові дані».

### 5.3. `Auth\TotpService` (Microsoft Authenticator)
- Секрет: `Base32(random_bytes(20))` = 160 біт, 32 символи; SHA1, 6 цифр, 30 с; `issuer=VPN-GW`, label — логін.
- Зберігається зашифрованим: `Crypto::encrypt` (sodium secretbox, `APP_KEY`), формат `nonce || ciphertext`.
- Перевірка: кроки `0, -1, +1`; крок має бути більшим за `last_step`; оновлення `last_step` атомарне (`UPDATE … WHERE last_step < ?`) — повторне використання коду неможливе навіть при паралельних запитах.
- `confirmEnrollment` зберігає секрет лише після правильного першого коду, повторна реєстрація заборонена (скидання — `bin/totp-reset.php`).

### 5.4. `Auth\EmailOtp`
Код `random_int` 6 цифр; у сесії лише `HMAC(APP_HMAC_KEY)` і час; TTL 300 с; одноразовий; адреса — тільки атрибут `mail` з AD.

### 5.5. `Security\RateLimiter`
Події й блокування — у SQLite (таблиці `rate_events`, `lockouts`), ключі `ip:…`, `user:…`, `mail:…`. Нова сесія лічильник не скидає.

| Подія | Ліміт (за замовчуванням) | Наслідок |
|---|---|---|
| Невдалий вхід з IP | 5 / 15 хв | блокування IP на `LOCKOUT_SECONDS` |
| Невдалий вхід на логін | 3 / 15 хв | блокування логіна; **перевіряється до звернення в AD** |
| Невірний код | 3 спроби за сесію | блокування IP і логіна, знищення сесії |
| Email OTP | 3 / год | відмова в надсиланні |
| Нові з'єднання з IP | 60 / хв | nftables на сервері (аналог `limit_req`) |

### 5.6. MikroTik: `RouterOsClient`, `AccessGranter`

```php
interface RouterOsClient {
    public function name(): string;
    public function findEntry(string $list, string $ip): ?string;
    public function addEntry(string $list, string $ip, string $timeout, string $comment): void;
    public function updateEntry(string $id, string $timeout, string $comment): void;
    public function removeEntry(string $id): void;
    public function dropSessions(string $username, string $ip): void; // PPP active + IPsec active-peers
}
```

| Операція | `RestClientV7` | `ApiSslClientV6` |
|---|---|---|
| find | `GET /rest/ip/firewall/address-list?list=&address=` | `/ip/firewall/address-list/print ?list= ?address=` |
| add | `PUT …/address-list` (JSON) | `…/add =list= =address= =timeout= =comment=` |
| update | `PATCH …/address-list/<.id>` | `…/set =.id=` |
| remove | `DELETE …/address-list/<.id>` | `…/remove =.id=` |
| drop | `/ppp/active?name=` + `/ip/ipsec/active-peers?remote-address=` → `DELETE` | те саме через `print` + `remove` |

- **Жодних CLI-рядків**: REST — JSON; API — кожне значення окреме слово (`ApiSslClientV6::query()`), керувальні символи вирізаються.
- TLS: REST — `CURLOPT_SSL_VERIFYPEER/HOST`, `CAINFO = MT_CA_FILE`, без проксі; API-SSL — **бібліотека за замовчуванням використовує анонімні шифри `ADH:ALL` без перевірки сертифіката**; клієнт явно задає `ciphers=HIGH:!aNULL…`, `verify_peer`, `verify_peer_name`, `cafile`. Сертифікат маршрутизатора має містити його IP у SAN.
- `AccessGranter::grant` — під `flock()`; для кожного маршрутизатора: знайти → оновити timeout або додати. Помилка одного маршрутизатора не зупиняє інші; наприкінці — `RouterOsException` (користувач бачить «Тимчасова помилка»), подробиці — подія `mt_error`. Записує видачу в таблицю `grants`.
- `revoke` — видаляє запис, розриває PPP-сесію і IPsec active-peer (видалення запису саме по собі вже встановлений тунель не рве).
- Коментар у MikroTik: `User: <лише [A-Za-z0-9._-]> via VPN-2FA`. IP перед записом перевіряється ще раз (публічна IPv4).

### 5.7. Шаблони і стилі
`View::render` дає шаблонам `$e` (екранування `htmlspecialchars`), `$csrf`, `$base`. Весь вивід — через `$e()`. CSP: `default-src 'self'; script-src 'none'; img-src 'self' data:` (QR — SVG data-URI). На сторінках **не виводиться** версія ПЗ.

### 5.8. Журнал аудиту
JSON-рядок на подію → файл `LOG_PATH` (0600) + syslog `LOG_LOCAL5`. Поля: `event, app_version, user, ip, remote_addr, xff_raw, ua` + додаткові. Ключі, що містять `pass|code|secret|otp|token|csrf|session`, відкидаються автоматично; рядки від клієнта очищуються від керувальних символів і обрізаються.

Події: `login_ok, login_fail, login_blocked, mfa_ok, mfa_fail, mfa_not_enrolled, lockout, email_otp_sent, email_otp_limited, mt_add, mt_update, mt_revoke, mt_error, revoke_self, enroll_ok, enroll_fail, mail_sent, mail_skipped, mail_error, unlock, totp_reset, unhandled`.

### 5.9. Сповіщення
`Notifier` — PHPMailer, STARTTLS з перевіркою сертифіката (`SMTP_CA_FILE`), таймаут 10 с. Помилка листа доступ не блокує (`mail_error`).

---

### 5.10. Панель адміністратора

**Автентифікація** (`Admin\AdminAuth`, таблиця `admins`): локальні облікові записи, незалежні від AD (панель має працювати, коли налаштування AD хибні). Пароль — `password_hash(PASSWORD_ARGON2ID)`, мінімум 12 символів, без логіна в паролі; для неіснуючого логіна виконується `password_verify` з фіктивним хешем (однаковий час відповіді). Ліміти: `admin-ip:` за `RATE_LOGIN_IP`, `admin-user:` 5/900 с, блокування `LOCKOUT_SECONDS`. Сесія: `session_regenerate_id`, прив'язка до `REMOTE_ADDR`, 30 хв бездіяльності, 8 год максимум. Адміністратори керуються `bin/admin.php`; перший створює `install.sh`.

**Налаштування** (`Http\Admin\SettingsController`): форма будується з `SettingsSchema::SECTIONS` (типи полів `text, int, bool, select, secret, routers, categories`). Збереження:
1. зчитати поля; порожнє поле `secret` = не змінювати, прапорець `__clear` = очистити;
2. залишити лише змінені значення;
3. `Config::withPanelValues()`: повна валідація;
4. `panelChecks()`: CA-файли в `/etc/ssl/` і доступні для читання, каталог `LOG_PATH` дозволений і доступний для запису, для розкладу копій задано пароль;
5. `SettingsStore::save()`;
6. подія `admin_settings_saved` з переліком ключів, без значень.

Секрети в HTML не потрапляють. Кнопки «Перевірити» (`Admin\Diagnostics`) використовують збережену конфігурацію.

**Журнали**: `AuditLogger::fromConfig()` — файл (рівень `LOG_LEVEL`), `SyslogHandler` (facility), `SyslogUdpHandler` у `WhatFailureGroupHandler` (недоступний SIEM не ламає вхід). Кожна подія має `category` (`SettingsSchema::eventCategory`); категорії `admin` і `system` пишуться завжди. `Admin\LogReader` читає останні 2 МБ файлу для перегляду.

### 5.11. Агент: резервні копії й оновлення

Веб-процес (`vpngw`, PHP-FPM з вимкненими `exec/proc_open`) не може виконувати команди. Привілейовані дії виконує **агент** `bin/agent.php` від root:

```
панель ──(файл <id>.job, <id>.zip)──► /var/lib/vpn-gw/spool ──systemd path──► vpn-gw-agent.service (root)
       ◄──(<id>.json, <id>.log)────── /var/lib/vpn-gw-agent/status ◄──────────┘
                                       /var/backups/vpn-gw/*.vgbk
```

| Що | Як захищено |
|---|---|
| Файли завдань | недовірені: розмір ≤ 4 КБ, без символьних посилань, `id` за шаблоном, тип з білого списку, файл видаляється до виконання |
| Каталоги агента | власник root, без запису для групи/інших (`Agent::assertRootDir`) — веб не може підмінити їх посиланнями |
| Робота з SQLite | лише від `vpngw` через `bin/agent-helper.php` (`runuser`): root не створює файлів у каталозі БД |
| Код | `/srv/vpn-gw` належить `root:vpngw`, без права запису для застосунку: компрометація веба не змінює код, який запускає root |
| Команди | лише `proc_open` зі списком аргументів, без оболонки |

**Резервна копія** (`Agent::backup`): `VACUUM INTO` (знімок БД) + `manifest.json` + `.env`, `/etc/vpn-gw`, сертифікати, CA, конфігурація Apache/PHP-FPM/nftables/logrotate → `tar.gz` → `BackupArchive` (Argon2id `MODERATE` + `crypto_secretstream_xchacha20poly1305`, фрагменти 64 КБ, `TAG_FINAL` — виявляє обрізання) → `root:vpngw 0640`. Розклад: таймер щогодини, копія створюється, якщо година ≥ `BACKUP_HOUR` і сьогодні ще не було `auto`. Ротація — `BACKUP_KEEP`. Відновлення — `bin/restore.php` (лише шляхи з маніфесту).

**Оновлення** (`MaintenanceController::acceptUpload` → `Agent::runUpdateJob`):
1. веб: ім'я `vpn-gw-X.Y.zip`, SHA-256 з файлу `.sha256` або поля, сигнатура ZIP, версія новіша, ліміт 50 МБ;
2. агент: копія архіву в каталог root, повторна перевірка SHA-256, `ReleaseInspector` (один каталог верхнього рівня `vpn-gw-X.Y`, без `..`, абсолютних шляхів і символьних посилань, ≤ 5000 файлів і 200 МБ після розпакування), розпакування, перевірка `VERSION`;
3. резервна копія `preupdate`, знімок `/srv/vpn-gw` і конфігурації веб-сервера;
4. `install.sh --domain … --target … --yes` з таймаутом 20 хв;
5. перевірка `agent-helper.php health` = `ok X.Y`;
6. при помилці — відкат: код і конфігурація зі знімка, перезапуск PHP-FPM і Apache; невдала версія — у `/srv/vpn-gw.failed-<id>`.

Коди `install.sh`: 0 — OK, 1 — невдалі перевірки (тоді вирішує `health`), 2 — перервано.

> Обмеження: SHA-256 захищає від пошкодження, але не підтверджує автора. Оновлення через веб еквівалентне виконанню коду від root; права на панель — лише довіреним адміністраторам. Наступний крок — підпис релізу (Ed25519/minisign) з відкритим ключем на сервері.

---

## 6. База даних

Міграції — `src/Storage/migrations/NNN_*.sql`, виконуються всі по порядку, кожна ідемпотентна.
- `001_init.sql`: `totp_secrets(username, secret_enc, last_step, created_at, created_by)`, `rate_events(key, ts)`, `lockouts(key, until_ts, reason)`, `grants(id, username, ip, granted_at, expires_at, revoked_at)`.
- `002_admin.sql`: `settings(key, value, encrypted, updated_at, updated_by)`, `admins(username, password_hash, created_at, updated_at, last_login_at, last_login_ip)`.

Очищення — `bin/cleanup.php` щогодини (systemd timer).

---

## 7. CLI

| Команда (від користувача `vpngw`) | Призначення |
|---|---|
| `php bin/migrate.php` | створити / оновити схему |
| `php bin/cleanup.php` | прибрати старі події та прострочені блокування |
| `php bin/unlock.php --ip <IP> --by <адмін>` / `--user <логін>` | зняти блокування |
| `php bin/totp-reset.php --user <логін> --by <адмін>` / `--count` | скинути TOTP / кількість зареєстрованих |
| `php bin/mt-check.php find` / `grant` / `revoke <публічна IP>` | перевірка інтеграції з MikroTik без браузера |
| `php bin/admin.php list` / `create` / `passwd` / `delete <логін>` [`--random`, `--stdin`] | адміністратори панелі |
| `php bin/settings.php list` / `reset KEY` / `effective` / `init-backup-passphrase` | значення панелі |
| `php bin/agent-helper.php …` | службова, викликається агентом |
| **root:** `php bin/agent.php run` / `backup` | агент (зазвичай запускає systemd) |
| **root:** `php bin/restore.php <файл.vgbk> [--apply]` | вміст / відновлення резервної копії |

---

## 8. Тести і статичний аналіз

```bash
composer install && composer test && composer stan && composer audit
```

| Набір | Що перевіряє |
|---|---|
| `Security/*` | ClientIp (підробка XFF, CIDR, IPv6, ін'єкція), Cidr, Crypto (підробка шифротексту, чужий ключ), RateLimiter (вікно, блокування переживає нову сесію) |
| `Auth/*` | TOTP (сумісність URI з MS Authenticator, повтор коду, дрейф ±1/±2, пробіли), Email OTP, вхідні дані LDAP (ін'єкції, порожній пароль), `accountExpires` |
| `RouterOs/*` | add/update без дублікатів, revoke + drop, збій одного маршрутизатора, відмова для приватних IP, санітизація коментаря, API-слова |
| `ConfigTest` | fail closed на 33 типах некоректних значень |
| `Settings/*` | пріоритет панелі, заборона ключів доступу з панелі, шифрування секретів, `Config::load` з БД, зміна `APP_KEY` = помилка |
| `Admin/AdminPanelTest` | панель через Slim: 404 ззовні (зокрема `%61dmin`), вхід, блокування, CSRF, прив'язка до IP і тайм-аут, зміна пароля, збереження / відмова / очищення секретів / скидання до `.env`, кнопки перевірки, фільтри журналу, черга копій, завантаження копії, перевірки архіву оновлення |
| `Admin/AdminUnitsTest` | політика паролів, Argon2id, фільтр категорій журналу, розбір рядків журналу, черга завдань (підміна `id`) |
| `Agent/*` | шифрування копій (раунд-трип, хибний пароль, обрізання, підміна байта), `ReleaseInspector` на реальних zip (zip-slip, symlink, два каталоги, невідповідність `VERSION`) |
| `AppTest` | наскрізні сценарії через Slim: 404-маскування, заголовки, CSRF, повний вхід і відкликання, блокування після 3 кодів, блокування до AD, прив'язка до IP, TTL, реєстрація з офісу, `/enroll` ззовні = 404, екранування |
| `VersionTest` | `VERSION` = `Version::CURRENT` = `composer.json` |

Інтеграційні перевірки проти реальних AD і MikroTik — на тестовому стенді: `install.sh` (розділ «Зв'язок»), кнопки «Перевірити» в панелі і `bin/mt-check.php`. Агент перевірено від root на Linux: копія → `restore.php --apply`, оновлення (успіх, помилка інсталятора з відкатом, недійсна перевірка, повторна версія, підмінений архів), завантаження релізу через браузер.

---

## 9. Розгортання

Автоматично — `sudo ./install.sh --domain …` (див. `INSTALL_GUIDE.md`). Готові конфігурації в `deploy/`:
- віртуальний хост Apache: маскування 404, виконується лише `index.php`, заголовки безпеки, великі запити дозволені тільки для `/admin/update`;
- пул PHP-FPM: `open_basedir`, `disable_functions`, ліміти завантаження;
- systemd: `vpn-gw-cleanup.timer`, `vpn-gw-agent.{service,path,timer}`;
- logrotate: початкова версія, далі файл веде агент;
- nftables;
- скрипти RouterOS 6/7: drop-правила створюються вимкненими.

---

## 10. Як вносити зміни

- Новий маршрут — метод контролера + рядок у `App::create`; POST-форми мають містити поле `csrf`.
- Новий параметр `.env` — у `Config` (`REQUIRED` або `DEFAULTS` + валідація), `.env.example`, тест у `ConfigTest`. Якщо його треба редагувати в панелі — поле в `SettingsSchema::SECTIONS` (секрет — також у `SECRETS`), за потреби перевірка в `SettingsController::panelChecks`.
- Нова сторінка панелі — контролер у `Http/Admin` (наслідує `AdminController`, обгортка `guarded()`), маршрут під `/admin`, шаблон `admin_*.php`, пункт меню в `admin_layout.php`.
- Нова дія агента — лише фіксована операція в `Agent`, тип у `Jobs::TYPES`; дані із завдання вважати недовіреними.
- Нова операція на MikroTik — метод у `RouterOsClient` і обох реалізаціях, `FakeRouter` у тестах.
- Нова подія журналу — `AuditLogger::event('назва', …)`; якщо це попередження — додати в `WARN_EVENTS`; категорія — `SettingsSchema::eventCategory` (префікс `admin_` → `admin`).
- Перед комітом: `composer test && composer stan`.

## 11. Версії

Номер — у трьох місцях: `VERSION`, `src/Version.php`, `composer.json` (`VersionTest` стежить за збігом) + розділ у `CHANGELOG.md`. Реліз — архів `vpn-gw-X.Y.zip` з файлом контрольної суми `vpn-gw-X.Y.zip.sha256`. Версія пишеться в журнал аудиту і показується CLI, `install.sh` і внутрішньою панеллю, але не на публічних сторінках.

## 12. Відомі обмеження і відкриті питання

- Лише IPv4.
- Адміністратори панелі — лише локальні облікові записи, без другого фактора (вхід лише з внутрішньої мережі). Можливий розвиток: TOTP для адміністраторів або вхід через групу AD.
- Оновлення через веб перевіряє цілісність (SHA-256), а не автора релізу; підпис — у планах.
- Відновлення з резервної копії — лише з командного рядка (свідомо: це дія з найбільшими наслідками).
- Частковий запис: якщо один маршрутизатор недоступний, на інших запис може залишитися (обмежений timeout).
- Android 12+ не має вбудованого клієнта L2TP/IPsec — потрібен сторонній застосунок або перехід на IKEv2.
- Відкриті питання: доступність пошти ззовні за AD-паролем (`EMAIL_OTP_ENABLED`); поріг lockout в AD (`RATE_LOGIN_USER` має бути меншим); PPP-автентифікація L2TP (локальні секрети чи RADIUS); писати IP на всі маршрутизатори чи лише на обраний.
