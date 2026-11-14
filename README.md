# VPN-GW 0.1

Версія: **0.1** (файл `VERSION`; історія змін — `CHANGELOG.md`).

Портал двофакторного доступу до корпоративного VPN (L2TP/IPsec на MikroTik RouterOS 6 і 7).
Після перевірки доменного пароля (Active Directory, LDAPS) і коду з Microsoft Authenticator (TOTP)
портал додає публічну IP-адресу співробітника в `/ip firewall address-list` маршрутизаторів на 10 годин.
VPN-порти відкриті лише для адрес з цього списку.

Документація: `DEVELOPMENT.md` (технічне завдання), `INSTALL_GUIDE.md` (встановлення з нуля),
`VPN-GW_Як_це_працює.docx` (для користувачів).

## Структура

```
public/            webroot: index.php (front controller), assets/app.css
src/
  Config.php       валідація .env (fail closed)
  Container.php    сервіси (lazy)
  App.php          маршрути Slim 4, middleware, обробка помилок (усе невідоме -> 404)
  Auth/            LdapAuthenticator, TotpService (MS Authenticator), EmailOtp
  Security/        ClientIp, Cidr, Crypto (sodium), RateLimiter (серверний)
  RouterOs/        RestClientV7 (REST), ApiSslClientV6 (API-SSL), AccessGranter
  Http/            контролери, Session, View, middleware (CSRF, заголовки, лише внутрішня мережа)
  Storage/         SQLite + міграції
  Log/             AuditLogger (JSON -> файл + syslog LOCAL5 -> SIEM)
  Mail/            Notifier (PHPMailer, STARTTLS з перевіркою сертифіката)
templates/         сторінки (весь вивід екранується)
bin/               migrate, cleanup, unlock, totp-reset, mt-check
deploy/            Apache vhost, PHP-FPM pool, systemd timer, logrotate, nftables, скрипти RouterOS 6/7
install.sh         автоматичне встановлення з перевіркою залежностей
tests/             PHPUnit 11 (unit + наскрізні HTTP-сценарії з фейковими AD і MikroTik)
```

## Маршрути (під `APP_BASE_PATH`, напр. `/k7f3x`)

| Метод | Шлях | Призначення |
|---|---|---|
| GET | `/` | Форма логіна |
| POST | `/login` | Крок 1: AD |
| GET/POST | `/verify` | Крок 2: код TOTP (або Email OTP) → запис у MikroTik |
| POST | `/verify/email` | Резервний код на пошту (якщо `EMAIL_OTP_ENABLED=true`) |
| GET | `/result` | «Доступ дозволено до ЧЧ:ХХ» |
| POST | `/revoke` | Відкликати доступ (запис + PPP-сесія + IPsec peer) |
| GET/POST | `/enroll` | Реєстрація Microsoft Authenticator — **лише з `INTERNAL_NETWORKS`**, ззовні 404 |

## Автоматичне встановлення: `install.sh`

На чистому Debian 13 (або 12) з налаштованими мережею, DNS і синхронізацією часу:

```bash
sudo ./install.sh --check                              # лише перевірки, нічого не змінює
sudo ./install.sh --domain vpn-gw.company.ua           # встановлення / оновлення
sudo -u vpngw nano /srv/vpn-gw/.env                    # заповнити AD, MikroTik, SMTP
sudo ./install.sh --domain vpn-gw.company.ua           # повторний запуск: міграція БД і перевірки
```

Що робить скрипт (11 кроків, підсумок OK/WARN/FAIL, код виходу 1 при будь-якому FAIL):

1. ОС, архітектура, диск, пам'ять, **синхронізація часу** (без неї TOTP не працює).
2. Ставить відсутні пакети (Apache, PHP-FPM з потрібними модулями, Composer, chrony, утиліти); видаляє `mod_php`, якщо є.
3. Перевіряє версію PHP (≥ 8.2) і всі розширення: ldap, curl, sodium, pdo_sqlite, mbstring, openssl, sockets тощо.
4. Модулі Apache (`mpm_event`, `proxy_fcgi`, `rewrite`, `headers`, `ssl`…), `ServerTokens Prod`, вимикає стандартні сайти.
5. Користувач `vpngw`, каталоги з правильними правами.
6. Копіює код у `/srv/vpn-gw`, `composer install --no-dev`, `composer audit`.
7. `.env`: якщо немає — створює з **новими ключами** і випадковим шляхом порталу; перевіряє права 600, файли CA, незаповнені значення і проганяє `Config::load`.
8. Міграція БД (лише коли `.env` заповнено).
9. Пул PHP-FPM, віртуальний хост (домен і шлях підставляються з `.env`; без сертифіката — тимчасово самопідписаний), systemd-таймер, logrotate; `--with-nftables` — також firewall сервера.
10. **Зв'язок**: TLS і довіра до сертифіката для кожного контролера домену (LDAPS), кожного MikroTik з `ROUTERS`, SMTP STARTTLS; DNS (і попередження про AAAA-запис).
11. Smoke-тест: `/` і `/index.php` → 404, шлях порталу → 200, версія Apache прихована.

Скрипт ідемпотентний: наявні `.env`, база й сертифікати не перезаписуються. Маршрутизатори він **не змінює** — `.rsc`-скрипти з `deploy/mikrotik/` застосовуються вручну в Safe Mode.

## Встановлення вручну (коротко; повністю — INSTALL_GUIDE.md)

```bash
sudo apt install apache2 php8.4-fpm php8.4-cli php8.4-ldap php8.4-curl php8.4-sqlite3 \
                 php8.4-mbstring php8.4-xml php8.4-intl php8.4-opcache composer
cd /srv/vpn-gw
sudo -u vpngw composer install --no-dev --classmap-authoritative
cp .env.example .env && chmod 600 .env      # заповнити; ключі:
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
sudo -u vpngw php bin/migrate.php
# deploy/apache/vpn-gw.conf -> /etc/apache2/sites-available/, a2ensite vpn-gw
# deploy/php-fpm/vpngw.conf -> /etc/php/8.4/fpm/pool.d/
# deploy/systemd/* -> /etc/systemd/system/, systemctl enable --now vpn-gw-cleanup.timer
# deploy/mikrotik/routeros{6,7}.rsc — на маршрутизаторах у Safe Mode
```

> `composer.lock` не включено: залежності вперше розв'язуються на сервері (`composer install`
> створить lock-файл). Закомітьте його, щоб наступні розгортання були відтворюваними.

## Перевірка

```bash
composer install            # з dev-залежностями, на машині розробника
composer test               # PHPUnit
composer stan               # PHPStan, рівень 8
composer audit
```

Інтеграція з маршрутизаторами (на тестовому стенді або з тестовою IP):

```bash
sudo -u vpngw php bin/mt-check.php grant  198.51.100.99
sudo -u vpngw php bin/mt-check.php find   198.51.100.99
sudo -u vpngw php bin/mt-check.php revoke 198.51.100.99
```

## Адміністрування

```bash
sudo -u vpngw php bin/unlock.php --user ivanenko --by admin.petrenko
sudo -u vpngw php bin/unlock.php --ip 203.0.113.45 --by admin.petrenko
sudo -u vpngw php bin/totp-reset.php --user ivanenko --by admin.petrenko   # втрачений телефон
sudo -u vpngw php bin/totp-reset.php --count
```

## Ключові рішення безпеки

- **IP клієнта**: `X-Forwarded-For` лише від `TRUSTED_PROXIES`, береться крайнє праве значення; приймається тільки одна публічна IPv4 (CIDR, приватні, IPv6 — відмова). Apache видаляє клієнтський XFF.
- **MikroTik**: жодних CLI-рядків; REST (JSON) або API-слова, де кожне значення — окреме поле. TLS до маршрутизатора з перевіркою сертифіката корпоративного CA. Для RouterOS 6 явно вимкнено анонімні шифри (ADH), які бібліотека використовує за замовчуванням.
- **AD**: лише `ldaps://` з перевіркою сертифіката; порожній пароль відхиляється до bind; група з урахуванням вкладених груп; вимкнені та протерміновані облікові записи — відмова.
- **TOTP**: секрет 160 біт, зашифрований (sodium secretbox, `APP_KEY`); кожен крок часу приймається один раз (атомарний `last_step`); реєстрація лише з внутрішньої мережі та лише з підтвердженням першим кодом.
- **Ліміти**: у SQLite, а не в сесії; блокування логіна перевіряється **до** звернення в AD (захист від lockout облікових записів).
- **Сесія**: `__Host-` cookie, Secure, HttpOnly, SameSite=Strict, нова ID після кожного кроку, прив'язка до IP, TTL кроків.
- **Журнал**: JSON, ключі з `pass|code|secret|otp|token|csrf|session` автоматично відкидаються.
- **Збій конфігурації**: портал не стартує (500) замість роботи з небезпечними налаштуваннями.

## Відомі обмеження

- Видалення запису зі списку не рве вже встановлений тунель; тому `revoke` додатково видаляє активну PPP-сесію та IPsec active-peer.
- Якщо один з маршрутизаторів недоступний, на інших запис уже може бути зроблено (він обмежений timeout); користувач бачить «Тимчасова помилка», подія `mt_error` — у журналі.
- Лише IPv4 (публікуйте портал тільки A-записом).
