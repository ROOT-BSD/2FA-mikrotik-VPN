# VPN-GW: посібник розробника

**Версія документа 5.0 · 21.11.2026 · відповідає коду VPN-GW 0.10.9** · Супровідні документи: `INSTALL_GUIDE.md` (встановлення), `ADMIN_GUIDE.md` (панель адміністратора), `USER_GUIDE.md` (для співробітників), `VPN-GW_Як_це_працює.docx` (огляд), `CHANGELOG.md`

Документ описує архітектуру і внутрішню будову реалізованого порталу: де що лежить, як працює кожен модуль, які рішення безпеки закладено і як вносити зміни. Код — у репозиторії; тут лише ключові фрагменти.

---

## 0. Коротко

Портал відкриває VPN-порти (L2TP/IPsec) на MikroTik **тільки для IP-адреси користувача**, який:

1. пройшов перевірку логіна й пароля в Active Directory (LDAPS, група `VPN-Users`, з урахуванням вкладених груп);
2. ввів код Microsoft Authenticator (TOTP; резервно — код на пошту з AD, вимкнено за замовчуванням);
3. звертається з валідної публічної IPv4.

IP додається у `/ip firewall address-list` (список `MT_LIST`, timeout `MT_TIMEOUT`, за замовчуванням 10 год) **на всіх** маршрутизаторах з `ROUTERS`. Протокол — для кожного маршрутизатора: API (RouterOS 7 — REST, RouterOS 6 — API-SSL) або SSH (RouterOS 6 і 7).

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
| Frontend | HTML-шаблони PHP + Bootstrap 5.3.3 (локальна копія `public/assets/bootstrap.min.css`, MIT) і `public/assets/app.css`; оформлення як у ITSM System; **без JS і без збірки** |
| Сховище | SQLite (WAL) |
| Розробка | `phpunit/phpunit` ^11, `phpstan/phpstan` ^2 (рівень 8) |

---

## 2. Структура проєкту

```
VERSION                    номер версії (єдине джерело); див. розділ 11
install.sh                 встановлення на Debian з перевіркою залежностей (INSTALL_GUIDE)
public/
  index.php                front controller: Config::load -> сесія -> App
  assets/bootstrap.min.css Bootstrap 5.3.3 (без змін, лише без рядка sourceMappingURL)
  assets/app.css           власні стилі поверх Bootstrap
src/
  Config.php               .env + значення з панелі, валідація (fail closed), ConfigException.php
  Container.php            сервіси (lazy, перевизначення в тестах)
  App.php                  маршрути Slim, порядок middleware, обробник помилок
  Version.php              Version::CURRENT
  Auth/                    Authenticator (інтерфейс), LdapAuthenticator, AuthResult, TotpService, EmailOtp
  Security/                ClientIp, Cidr, Crypto, RateLimiter
  RouterOs/                RouterOsClient (інтерфейс), RestClientV7, ApiSslClientV6,
                           SshClient + SshSession / PhpseclibSession (SSH),
                           RouterOsFactory, AccessGranter, AddressLists, RouterOsException
  Http/                    Controller (база), LoginController, MfaController, ResultController,
                           EnrollController, Session, View
  Http/Middleware/         SecurityHeaders, Csrf, InternalNetworkOnly
  Http/Admin/              панель: AdminController (база), AuthController, DashboardController,
                           SettingsController, LogsController, MaintenanceController
  Admin/                   AdminAuth (облікові записи), Jobs (черга агента), LogReader, Diagnostics
  Settings/                SettingsSchema (що редагується в панелі), SettingsStore (БД, шифрування)
  Agent/                   Agent (root), BackupArchive, ReleaseInspector, Proc
  Storage/                 Database, migrations/001_init.sql, 002_admin.sql, 003_user_email.sql
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
| `PRIVATE_CLIENT_NETWORKS` | IPv4 CIDR (не ширше /8), де приватні адреси клієнтів дозволені для входу й address-list; за замовчуванням порожньо |
| `INTERNAL_NETWORKS` | список IPv4 CIDR, звідки дозволено `/enroll` і `/admin` |
| `LDAP_SECURITY` | один з: `ldaps` (за замовчуванням), `ldaps-insecure`, `starttls`, `starttls-insecure`, `plain` |
| `LDAP_HOSTS` | `ldaps://хост[:порт]` для режимів `ldaps*`, `ldap://хост[:порт]` для інших; декілька — через кому (перебираються, якщо DC недоступний) |
| `LDAP_CA_FILE` | обов'язковий лише для `ldaps` і `starttls` |
| `ROUTERS` | JSON-масив `{name, host, version, port, transport}`; version — 6 або 7, transport — `api` (за замовчуванням) або `ssh`; у `.env` — **в одинарних лапках** |
| `ROUTERS[].list` | власний address-list маршрутизатора (`[A-Za-z0-9_.-]{1,64}`, необов'язково); порожньо — `MT_LIST`. `Config::routerList($router)`, `Config::routerLists()`; `AccessGranter` отримує мапу «маршрутизатор → список» |
| `ROUTERS[].user` | власний користувач маршрутизатора (`[A-Za-z0-9._@-]{1,64}`, необов'язково); порожньо — типовий користувач протоколу |
| `ROUTER_PASSWORDS` | секрет, JSON `{"назва": "пароль"}`: власні паролі маршрутизаторів; записи для неіснуючих маршрутизаторів ігноруються. `Config::routerCredentials($router)` повертає власні або типові облікові дані |
| `MT_USER`, `MT_PASS`, `MT_CA_FILE` | типові облікові дані для `transport=api`; `MT_CA_FILE` обов'язковий, якщо є API-маршрутизатори; `MT_USER` — якщо є API-маршрутизатор без власного користувача |
| `MT_SSH_USER`, `MT_SSH_PASS`, `MT_SSH_KEY`, `MT_SSH_KEY_PASS` | типові облікові дані для `transport=ssh`: кожен SSH-маршрутизатор повинен мати користувача (власного або типового) і пароль (власний або типовий) чи спільний ключ |
| `MT_SSH_HOSTKEYS` | JSON `{"назва": "SHA256:…"}` — підтверджені ключі хостів; ведеться панеллю |
| `MT_LIST` | `[A-Za-z0-9_.-]{1,64}` |
| `MT_TIMEOUT` | `HH:MM:SS` |
| `RATE_*` | `N/секунд`, напр. `5/900` |
| `EMAIL_OTP_ENABLED` | `false` за замовчуванням |
| `SMTP_HOST` | порожній = листи вимкнено (подія `mail_skipped`) |
| `IT_CONTACT` | текст із пробілами — **у подвійних лапках** |
| `USER_GUIDE_PDF` | шлях до PDF-пам'ятки, що надсилається після реєстрації автентифікатора; порожньо — `doc/VPN-GW_Інструкція_підключення.pdf` з архіву |
| `DB_PATH`, `LOCK_DIR` | шляхи в `/var/lib/vpn-gw` |
| `LOG_PATH` | абсолютний шлях; панель додатково дозволяє лише `DATA_DIR/logs/*.log` і `/var/log/vpn-gw/*.log` |
| `LOG_LEVEL` / `LOG_EVENTS` | `info` або `warning` / підмножина `auth, access, enroll, mail, admin, system` |
| `LOG_SYSLOG`, `LOG_SYSLOG_FACILITY`, `LOG_REMOTE` | `true/false`, `local0`–`local7`, `хост:порт` (UDP) |
| `LOG_RETENTION_DAYS` | 1–3650 (агент пише в `/etc/logrotate.d/vpn-gw`) |
| `BACKUP_ENABLED`, `BACKUP_HOUR`, `BACKUP_KEEP`, `BACKUP_PASSPHRASE` | `true/false`, 0–23, 1–365, ≥ 12 символів |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_FROM` | ім'я хоста, 1–65535, e-mail; без керувальних символів |
| `LDAP_DOMAIN` | DNS-домен AD (`nerc.gov.ua`); з нього: логін для bind `user@домен`, база пошуку `DC=nerc,DC=gov,DC=ua` |
| `LDAP_VPN_GROUP` | назва групи VPN (`[A-Za-z0-9 _.-]`), DN знаходиться після bind пошуком групи за `sAMAccountName` або `cn` |
| `LDAP_UPN_SUFFIX`, `LDAP_BASE_DN`, `LDAP_VPN_GROUP_DN` | застарілі (0.1–0.3): діють лише якщо `LDAP_DOMAIN` / `LDAP_VPN_GROUP` порожні |
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
| GET/POST | `/admin/routers/add`, `/admin/routers/edit/{назва}` | Admin\Settings::router | сторінка маршрутизатора (`router[...]`, `action=save` / `lists`): `ROUTERS`, `ROUTER_PASSWORDS`, `MT_SSH_HOSTKEYS` перебудовуються зі збережених маршрутизаторів з цим заміненим / доданим тими самими перевірками (`readRouters` тощо) |
| GET | `/admin/authenticators` (`q`) | Admin\Authenticators | зареєстровані автентифікатори (`Database::listTotp`, без секретів) |
| POST | `/admin/authenticators/delete` | Admin\Authenticators | видалення прив'язки (`Database::deleteTotp`), подія `admin_totp_reset` |
| POST | `/admin/grants/revoke` | Admin\Dashboard | «Відключити»: `AccessGranter::revoke` для активного доступу з таблиці `grants`, подія `admin_revoke` |
| GET/POST | `/admin/settings/{mikrotik,mail,ad,logs,backup}` | Admin\Settings | `action=save` / `test` / `reset:KEY`; для mikrotik ще `lists`, `delete_router` і `toggle_router` (+ `router=<назва>`; окремі форми `#router-delete`, `#router-toggle`, кнопки прив'язані атрибутом `form`) |
| GET | `/admin/logs` | Admin\Logs | перегляд журналу (`file` — назва файлу або `*` — усі файли, `q`, `level`, `category`) |
| GET | `/admin/logs/download` | Admin\Logs | завантаження файлу журналу (`file` — назва зі списку) |
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
`X-Forwarded-For` враховується лише якщо `REMOTE_ADDR` ∈ `TRUSTED_PROXIES`, береться крайнє праве значення. Результат — **одна IPv4**: публічна (`FILTER_FLAG_IPV4 | NO_PRIV_RANGE | NO_RES_RANGE`) або приватна з `PRIVATE_CLIENT_NETWORKS` (`ClientIp::isAcceptable`, loopback і 0.0.0.0/8 — ніколи), інакше `null` і відмова; `AccessGranter` перевіряє те саме. Apache додатково видаляє клієнтський XFF (`RequestHeader unset … early`).

### 5.2. `Auth\LdapAuthenticator`
- До будь-якого виклику LDAP: логін `^[A-Za-z0-9._-]{1,64}$`, пароль непорожній і ≤ 256 символів (порожній пароль = анонімний bind, який AD підтверджує).
- Режим `LDAP_SECURITY` → `LdapAuthenticator::tlsOptions()`: `ldaps` / `starttls` — `LDAP_OPT_X_TLS_REQUIRE_CERT = DEMAND`, CA з `LDAP_CA_FILE`; `*-insecure` — `REQUIRE_CERT = NEVER` (шифрування без перевірки сертифіката); `plain` — без TLS. Опції застосовуються до з'єднання через `LDAP_OPT_X_TLS_NEWCTX` (якщо константа є — Debian/OpenLDAP). Таймаути 5 с.
- `starttls*`: `ldap_start_tls()` після налаштування; якщо не вдалося — наступний DC, **ніколи** не bind без TLS.
- Логін: `user` або `user@<LDAP_DOMAIN>` → `normalizeUsername()` (до лімітів спроб, тож обидві форми мають спільний лічильник); інший домен — відмова.
- Bind `логін@домен`; `errno -1` (сервер недоступний) → наступний DC.
- Група: DN знаходиться за назвою (`sAMAccountName` або `cn`, рівно один результат) від імені користувача; не знайдено — `group_not_found`.
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
    /** @return array<string, array{entries: int, rules: int}> — лише для панелі (вибір MT_LIST) */
    public function addressLists(): array;
}
```

| Операція | `RestClientV7` | `ApiSslClientV6` |
|---|---|---|
| find | `GET /rest/ip/firewall/address-list?list=&address=` | `/ip/firewall/address-list/print ?list= ?address=` |
| add | `PUT …/address-list` (JSON) | `…/add =list= =address= =timeout= =comment=` |
| update | `PATCH …/address-list/<.id>` | `…/set =.id=` |
| remove | `DELETE …/address-list/<.id>` | `…/remove =.id=` |
| drop | `/ppp/active?name=` + `/ip/ipsec/active-peers?remote-address=` → `DELETE` | те саме через `print` + `remove` |
| lists | `GET …/address-list?.proplist=list` + `GET /ip/firewall/{filter,nat,mangle,raw}?.proplist=src-address-list,dst-address-list` | те саме через `print =.proplist=` |

`SshClient::addressLists()` виконує фіксований скрипт `SshClient::LISTS_SCRIPT` (без зовнішніх значень): записи рахуються на маршрутизаторі (асоціативний масив), посилання з правил виводяться рядками `R <назва>`, кінець — `END`; без `END` відповідь вважається помилкою. `AddressLists::build()` об'єднує результат, знімає `!` (заперечення) і відкидає назви, що не підходять для `MT_LIST`. Панель: `Diagnostics::addressLists()` → дія `lists` у `SettingsController` → поле типу `mtlist` (select + «Новий список»).

- **API — жодних CLI-рядків**: REST — JSON; API-SSL — кожне значення окреме слово (`ApiSslClientV6::query()`), керувальні символи вирізаються.
- TLS: REST — `CURLOPT_SSL_VERIFYPEER/HOST`, `CAINFO = MT_CA_FILE`, без проксі; API-SSL — **бібліотека за замовчуванням використовує анонімні шифри `ADH:ALL` без перевірки сертифіката**; клієнт явно задає `ciphers=HIGH:!aNULL…`, `verify_peer`, `verify_peer_name`, `cafile`. Сертифікат маршрутизатора має містити його IP у SAN.
- **SSH (`SshClient`)** — консольні команди через exec-канал (phpseclib 3, `PhpseclibSession`):

  | Операція | Команда |
  |---|---|
  | find | `:put [:tostr [/ip firewall address-list find list="L" address="IP"]]` → `*1A` / `*1A;*1B` / порожньо |
  | add | `/ip firewall address-list add list="L" address="IP" timeout=HH:MM:SS comment="…"` |
  | update | `/ip firewall address-list set *ID timeout=… comment="…"` |
  | remove | `/ip firewall address-list remove *ID` |
  | drop | `/ppp active remove [find name="U"]`, `/ip ipsec active-peers remove [find remote-address=IP]` |

  Значення **не екрануються, а перевіряються за білим списком** (`list`/`user` — `[A-Za-z0-9_.-]`, коментар — `[A-Za-z0-9 _.:-]`, IP — IPv4, timeout — `\d{1,3}:\d\d:\d\d`, id — `\*[0-9A-F]+`); інакше `RouterOsException` і команда не надсилається. Будь-який вивід команд add/set/remove — повідомлення про помилку RouterOS. До логіна додається `+cet` (без кольорів і визначення терміналу).
- **Ключ хоста SSH**: `hostKeyFingerprint()` (SHA-256, як `ssh-keygen -lf`) перевіряється **до** входу. Немає збереженого ключа → відмова (портал); `RouterOsFactory::fromConfig($cfg, trustOnFirstUse: true)` — лише для кнопки «Перевірити MikroTik», яка показує і зберігає відбиток у `MT_SSH_HOSTKEYS`. Ключ не збігається → відмова «host key CHANGED». Зміна адреси/порту або «скинути» в панелі видаляє збережений ключ (`SettingsController::readHostKeys`).
- `AccessGranter::grant` — під `flock()`; для кожного маршрутизатора: знайти → оновити timeout або додати. Помилка одного маршрутизатора не зупиняє інші; наприкінці — `RouterOsException` (користувач бачить «Тимчасова помилка»), подробиці — подія `mt_error`. Записує видачу в таблицю `grants`.
- `revoke` — видаляє запис, розриває PPP-сесію і IPsec active-peer (видалення запису саме по собі вже встановлений тунель не рве).
- Коментар у MikroTik: `User: <лише [A-Za-z0-9._-]> via VPN-2FA`. IP перед записом перевіряється ще раз (публічна IPv4).

### 5.7. Шаблони і стилі
`View::render` дає шаблонам `$e` (екранування `htmlspecialchars`), `$csrf`, `$base`. Весь вивід — через `$e()`. CSP: `default-src 'self'; script-src 'none'; img-src 'self' data:` (QR — SVG data-URI; Bootstrap також малює стрілки списків і перемикачі як SVG data-URI). На публічних сторінках **не виводиться** версія ПЗ (у панелі — у підвалі).

Оформлення взято з ITSM System (`ROOT-BSD/itsm-system`): Bootstrap 5, `body.bg-light`, темне меню `navbar-dark bg-dark`, `container py-4`, картки `card` з `card-header`, таблиці `table table-sm` / `table-bordered`, `alert-*`, `badge bg-*`, кнопки `btn-primary` / `btn-outline-*`. Відмінності від ITSM: Bootstrap не з CDN, а локальний файл (CSP `style-src 'self'`, сервер без доступу до CDN), без `bootstrap.bundle.js` (CSP `script-src 'none'`), тому меню панелі завжди розгорнуте (`navbar-expand`) і переноситься на вузьких екранах. Публічні сторінки — `layout.php` (вузька колонка по центру, як вхід ITSM), панель — `admin_layout.php` (меню розділів і вкладки підрозділів). Дати в шаблонах — через `$dt(...)` (`View::formatDate`: `d.m.Y H:i:s` у часовому поясі сервера; приймає ATOM, `Y-m-d H:i:s` і Unix-час). Класи з `app.css`: `form-card-sm/md/xs` (ширина форм), `label-col`, `table-compact-rows`, `router-card` (картка маршрутизатора, лише перегляд), `form-card-lg` (сторінка маршрутизатора), `connected-routers` (таблиця підключених), `code` (поле коду), `result-icon`.

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
- `003_user_email.sql`: `user_emails(username, email, created_at, updated_at)` — адреса, вказана під час першої реєстрації автентифікатора (`EnrollController::confirm`: перевіряється до коду, зберігається після успішної реєстрації, подія `enroll_email`); туди `Notifier::userGuide()` надсилає PDF `Config::userGuidePdf()` (`USER_GUIDE_PDF` або `doc/VPN-GW_Інструкція_підключення.pdf`). Адреса показується на сторінці «Автентифікатори» і підставляється при повторній реєстрації; сповіщення про доступ і код на e-mail, як і раніше, йдуть на адресу з AD (`mail`).

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
| `RouterOs/*` | add/update без дублікатів, revoke + drop, збій одного маршрутизатора, відмова для приватних IP, санітизація коментаря, API-слова; SSH: точні команди, розбір відповіді, 9 спроб ін'єкції (відмова без надсилання), незатверджений і змінений ключ хоста (пароль не надсилається), TOFU, відбиток = `ssh-keygen -lf` |
| `Settings/ConnectionSettingsTest` | режими LDAP (схеми, CA лише з перевіркою), вимоги для API- і SSH-маршрутизаторів, шифрування параметрів підключення, міграція відкритих значень 0.2, TLS-опції LDAP за режимом |
| `ConfigTest` | fail closed на 33 типах некоректних значень |
| `Settings/*` | пріоритет панелі, заборона ключів доступу з панелі, шифрування секретів, `Config::load` з БД, зміна `APP_KEY` = помилка |
| `Admin/AdminPanelTest` | панель через Slim: 404 ззовні (зокрема `%61dmin`), вхід, блокування, CSRF, прив'язка до IP і тайм-аут, зміна пароля, збереження / відмова / очищення секретів / скидання до `.env`, кнопки перевірки, фільтри журналу, черга копій, завантаження копії, перевірки архіву оновлення |
| `Admin/AdminUnitsTest` | політика паролів, Argon2id, фільтр категорій журналу, розбір рядків журналу, черга завдань (підміна `id`) |
| `Agent/*` | шифрування копій (раунд-трип, хибний пароль, обрізання, підміна байта), `ReleaseInspector` на реальних zip (zip-slip, symlink, два каталоги, невідповідність `VERSION`) |
| `AppTest` | наскрізні сценарії через Slim: 404-маскування, заголовки, CSRF, повний вхід і відкликання, блокування після 3 кодів, блокування до AD, прив'язка до IP, TTL, реєстрація з офісу, `/enroll` ззовні = 404, екранування |
| `VersionTest` | `VERSION` = `Version::CURRENT` = `composer.json` |

Інтеграційні перевірки проти реальних AD і MikroTik — на тестовому стенді: `install.sh` (розділ «Зв'язок»), кнопки «Перевірити» в панелі і `bin/mt-check.php`. SSH перевірено проти справжнього OpenSSH-сервера з емулятором консолі RouterOS: підтвердження ключа кнопкою, видача / продовження / відкликання доступу, вхід паролем і ключем ed25519, хибний пароль, підмінений ключ хоста (жодної команди на «чужому» сервері). Агент перевірено від root на Linux: копія → `restore.php --apply`, оновлення (успіх, помилка інсталятора з відкатом, недійсна перевірка, повторна версія, підмінений архів), завантаження релізу через браузер.

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
- Новий параметр `.env` — у `Config` (`REQUIRED` або `DEFAULTS` + валідація), `.env.example`, тест у `ConfigTest`. Якщо його треба редагувати в панелі — поле в `SettingsSchema::SECTIONS`; секрет (не показується в браузері) — у `SECRETS`, параметр, що має зберігатися зашифрованим, але показуватися, — у `ENCRYPTED`; за потреби перевірка в `SettingsController::panelChecks`.
- Нова сторінка панелі — контролер у `Http/Admin` (наслідує `AdminController`, обгортка `guarded()`), маршрут під `/admin`, шаблон `admin_*.php`, пункт меню в `admin_layout.php`.
- Нова дія агента — лише фіксована операція в `Agent`, тип у `Jobs::TYPES`; дані із завдання вважати недовіреними.
- Нова операція на MikroTik — метод у `RouterOsClient` і обох реалізаціях, `FakeRouter` у тестах.
- Нова подія журналу — `AuditLogger::event('назва', …)`; якщо це попередження — додати в `WARN_EVENTS`; категорія — `SettingsSchema::eventCategory` (префікс `admin_` → `admin`).
- Перед комітом: `composer test && composer stan`.

## 11. Версії

Номер — у трьох місцях: `VERSION`, `src/Version.php`, `composer.json` (`VersionTest` стежить за збігом) + розділ у `CHANGELOG.md`. Реліз — архів `vpn-gw-X.Y.zip` з файлом контрольної суми `vpn-gw-X.Y.zip.sha256`. Версія пишеться в журнал аудиту і показується CLI, `install.sh` і внутрішньою панеллю, але не на публічних сторінках.

## 12. Відомі обмеження і відкриті питання

- Лише IPv4.
- SSH-команди перевірено на емуляторі консолі RouterOS, не на справжньому маршрутизаторі: перед запуском — `mt-check` на тестовому CHR 6 і 7 (особливо `find` за `address` і `active-peers` у RouterOS 6).
- Режими LDAP без перевірки сертифіката і без шифрування — компроміс безпеки; панель і `install.sh` про них попереджають.
- Адміністратори панелі — лише локальні облікові записи, без другого фактора (вхід лише з внутрішньої мережі). Можливий розвиток: TOTP для адміністраторів або вхід через групу AD.
- Оновлення через веб перевіряє цілісність (SHA-256), а не автора релізу; підпис — у планах.
- Відновлення з резервної копії — лише з командного рядка (свідомо: це дія з найбільшими наслідками).
- Частковий запис: якщо один маршрутизатор недоступний, на інших запис може залишитися (обмежений timeout).
- Android 12+ не має вбудованого клієнта L2TP/IPsec — потрібен сторонній застосунок або перехід на IKEv2.
- Відкриті питання: доступність пошти ззовні за AD-паролем (`EMAIL_OTP_ENABLED`); поріг lockout в AD (`RATE_LOGIN_USER` має бути меншим); PPP-автентифікація L2TP (локальні секрети чи RADIUS); писати IP на всі маршрутизатори чи лише на обраний.
