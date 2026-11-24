# VPN-GW: встановлення на новий сервер — швидкий гайд

**VPN-GW 0.10.9 · 22.11.2026** · Послідовність команд від щойно встановленого Debian 13 без додаткового ПЗ до робочого порталу. Подробиці кожного кроку — `INSTALL_GUIDE.md`, робота в панелі адміністратора — `ADMIN_GUIDE.md`.

Час: близько 30–40 хвилин (без підготовки MikroTik і сертифікатів).

---

## 0. Що має бути до початку

| Що | Приклад |
|---|---|
| Сервер: Debian 13 «trixie», мінімальне встановлення (лише *SSH server* + *standard system utilities*), 2 vCPU, 2 ГБ RAM, 20 ГБ | ВМ у DMZ |
| Статична IP і доступ в Інтернет (apt, packagist.org) | `10.0.10.90` |
| DNS A-запис порталу (**без AAAA**) і публікація порту 443 (і 80 для Let's Encrypt) | `vpn-gw.company.ua` |
| Сертифікат корпоративного CA (PEM) | `company-ca.pem` |
| Дані AD: домен і назва групи VPN | `nerc.gov.ua`, `VPN-Users` |
| MikroTik: адреси, доступ адміністратора | `10.0.10.1` (v7), `10.0.20.1` (v6) |
| ПК адміністратора у внутрішній мережі (для панелі адміністратора) | `10.0.50.0/24` |
| Архів з кодом і файл контрольної суми | `vpn-gw-0.10.9.zip`, `vpn-gw-0.10.9.zip.sha256` |

> Усі команди нижче виконуються від **root**. Якщо в системі є `sudo` — `sudo -i`; якщо під час встановлення Debian задано пароль root — `su -`.

---

## 1. Базові пакети і час

```bash
apt update && apt full-upgrade -y
apt install -y sudo unzip curl ca-certificates chrony
timedatectl set-timezone Europe/Kyiv
```

Синхронізація часу з контролерами домену (критично для кодів TOTP):
```bash
cat > /etc/chrony/sources.d/company.sources <<'EOF'
server dc01.company.local iburst
server dc02.company.local iburst
EOF
systemctl restart chrony
sleep 10; chronyc tracking | grep -E "Leap|System time"
```
**Очікувано:** `Leap status : Normal`, зсув менше 0,1 с.

---

## 2. Сертифікат корпоративного CA

Скопіюйте `company-ca.pem` на сервер (наприклад, `scp company-ca.pem admin@10.0.10.90:/tmp/`), потім:
```bash
install -m 644 /tmp/company-ca.pem /etc/ssl/company-ca.pem
cp /etc/ssl/company-ca.pem /usr/local/share/ca-certificates/company-ca.crt
update-ca-certificates
```
**Очікувано:** `1 added`.

Якщо корпоративного CA немає: для AD можна вибрати в панелі режим без перевірки сертифіката (крок 7), а MikroTik підключити по SSH (крок 9) — тоді CA-файл не потрібен. Режим AD без перевірки сертифіката — тимчасове рішення.

---

## 3. Отримати код

Скопіюйте на сервер архів і файл контрольної суми, наприклад у `/tmp`:
- з Windows — WinSCP (протокол SFTP) або `scp` у PowerShell;
- з Linux / macOS: `scp vpn-gw-0.10.9.zip vpn-gw-0.10.9.zip.sha256 admin@10.0.10.90:/tmp/`

На сервері перевірте цілісність і розпакуйте:
```bash
cd /tmp && sha256sum -c vpn-gw-0.10.9.zip.sha256          # має бути: vpn-gw-0.10.9.zip: OK
cd /root && unzip -q /tmp/vpn-gw-0.10.9.zip && cd vpn-gw-0.10.9
```
**Очікувано:** `sha256sum` → `OK`; `cat VERSION` → `0.10.9`; у каталозі є `install.sh`.

> Усі наступні команди `./install.sh …` виконуйте з каталогу `/root/vpn-gw-0.8`.

---

## 4. Попередня перевірка

```bash
chmod +x install.sh
./install.sh --check
```
**Очікувано на чистому сервері:** FAIL щодо відсутніх пакетів, PHP і `.env` — це нормально, їх усуне наступний крок. Якщо FAIL щодо **часу** або **місця на диску** — спершу виправте.

---

## 5. Встановлення

```bash
./install.sh --domain vpn-gw.company.ua
```
Скрипт запитає підтвердження і виконає 11 кроків: пакети (Apache, PHP 8.4-FPM з розширеннями, Composer), модулі Apache, користувач `vpngw`, каталоги, код у `/srv/vpn-gw`, `composer install`, `.env` **з новими ключами і випадковим шляхом порталу**, база даних, пул PHP-FPM, віртуальний хост, таймери, агент резервного копіювання й оновлень.

Під час кроку 8 скрипт створює **адміністратора панелі** і **двічі запитує його пароль** (мінімум 12 символів). Інший логін задається параметром `--admin-user`. Якщо скрипт запущено з `--yes` або без терміналу, пароль генерується автоматично. Пароль шифрування резервних копій генерується завжди. Згенеровані паролі записуються у файл `/root/vpn-gw-credentials.txt` (права 0600).

**Очікувано:** «Створено адміністратора admin», «Пароль шифрування резервних копій задано» або «Згенеровано…». Попередження WARN «заповніть реальним значенням» для AD і MikroTik на цьому етапі нормальні: ці параметри заповнюються в панелі (крок 7). Наприкінці скрипт показує адреси порталу і панелі.

> ⚠️ Збережіть у сейфі паролів: `APP_KEY` з `/srv/vpn-gw/.env`, пароль адміністратора і пароль резервних копій з `/root/vpn-gw-credentials.txt`. Потім видаліть файл: `shred -u /root/vpn-gw-credentials.txt`. Без `APP_KEY` усім користувачам доведеться заново реєструвати Microsoft Authenticator. Без пароля резервних копій їх неможливо відновити.

---

## 6. TLS-сертифікат порталу

До цього кроку використовується тимчасовий самопідписаний сертифікат.

**Let's Encrypt** (портал доступний з Інтернету на портах 80 і 443):
```bash
apt install -y certbot python3-certbot-apache
certbot --apache -d vpn-gw.company.ua --agree-tos -m it@company.ua
```
Повторні запуски `install.sh` сертифікат Let's Encrypt зберігають.

**Власний сертифікат** (CA організації або комерційний):
```bash
install -d -m 700 /etc/ssl/vpn-gw
install -m 600 fullchain.pem privkey.pem /etc/ssl/vpn-gw/
./install.sh --domain vpn-gw.company.ua --yes
```
**Очікувано:** з іншої машини `curl -sI https://vpn-gw.company.ua/<APP_BASE_PATH>/` повертає `HTTP/2 200` без помилок сертифіката.

---

## 7. Налаштування в панелі адміністратора

**Внутрішні мережі.** Панель і реєстрація автентифікатора відкриваються лише з підмереж `INTERNAL_NETWORKS`. З інших адрес вони повертають 404. Якщо ПК адміністратора не входить у типове значення `10.0.0.0/8`, додайте його підмережу:
```bash
sudo -u vpngw nano /srv/vpn-gw/.env        # INTERNAL_NETWORKS=10.0.0.0/8,192.168.50.0/24
```

Відкрийте з ПК у внутрішній мережі **`https://vpn-gw.company.ua/<APP_BASE_PATH>/admin`** і увійдіть логіном і паролем з кроку 5.

| Розділ | Що заповнити | Перевірка |
|---|---|---|
| **Active Directory** | режим підключення (рекомендовано LDAPS з перевіркою сертифіката; є режими без перевірки і StartTLS), контролери `ldaps://dc01.company.local:636,…` (для StartTLS — `ldap://…:389`), домен (`nerc.gov.ua`), назва групи VPN (`VPN-Users`), CA `/etc/ssl/company-ca.pem` (лише для режимів з перевіркою) | «Перевірити AD» тестовим обліковим записом |
| **Пошта** | SMTP-сервер, порт 587, користувач і пароль, адреса відправника, контакт ІТ-служби; резервний код на e-mail — за політикою | «Надіслати» тестовий лист |
| **MikroTik** | маршрутизатори (назва, адреса, версія, протокол API або SSH, порт), облікові дані API (користувач, пароль, CA) і/або SSH (користувач, пароль або приватний ключ), address-list, тривалість | «Перевірити MikroTik» (після кроку 9); для SSH кнопка також підтверджує ключ хоста |
| **Журнали** | файл, строк зберігання, рівень, категорії подій, syslog, SIEM | розділ «Журнали» |
| **Резервні копії** | година запуску, кількість копій (пароль уже задано) | «Створити копію зараз» |

Кожне значення зберігається лише після перевірки всієї конфігурації. Паролі, ключі SSH і параметри підключення до маршрутизаторів зберігаються зашифрованими; паролі й ключі в браузер не повертаються. Біля кожного поля видно джерело значення: `.env`, панель або типове.

Параметри, які впливають на доступ до самої панелі, змінюються **лише в `.env`**: `APP_BASE_PATH`, `INTERNAL_NETWORKS`, `TRUSTED_PROXIES`, ліміти `RATE_*`. `RATE_LOGIN_USER` має бути меншим за поріг блокування облікового запису в AD.

---

## 8. Захист сервера (до запуску в експлуатацію)

**Адміністратор з sudo** (якщо під час встановлення Debian задано пароль root і звичайного адміністратора ще немає):
```bash
adduser admin && usermod -aG sudo admin
```

**SSH лише за ключами.** Зі свого ПК: `ssh-copy-id admin@10.0.10.90`, перевірте вхід за ключем, потім на сервері:
```bash
cat > /etc/ssh/sshd_config.d/10-hardening.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
EOF
sshd -t && systemctl reload ssh
```

**Firewall сервера.** Відредагуйте адреси в `deploy/nftables/nftables.conf` у каталозі з кодом, а не в `/srv/vpn-gw`: той перезаписується при кожному запуску. Потрібні адреси: адмін-підмережа, DC, **DNS-сервери з `/etc/resolv.conf`**, MikroTik, SMTP, SIEM. Потім:
```bash
nano deploy/nftables/nftables.conf
./install.sh --domain vpn-gw.company.ua --yes --with-nftables
```
> Не закривайте поточну SSH-сесію, доки в новому вікні не перевірите, що вхід працює.

**Автооновлення безпеки:**
```bash
apt install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades
```

---

## 9. MikroTik

На кожному маршрутизаторі (спершу — тестовий CHR), **у Safe Mode**. Виберіть протокол:

**Протокол API (REST / API-SSL):**
1. Імпортуйте сертифікат з IP маршрутизатора в SAN і назвіть його `router-api`.
2. Відкрийте `deploy/mikrotik/routeros7.rsc` або `routeros6.rsc`, замініть `10.0.10.90` на IP сервера і пароль, вставте команди в термінал.
3. Пароль `vpn_portal` введіть у панелі: **MikroTik → API: пароль → Зберегти**.

**Протокол SSH:**
1. Відкрийте `deploy/mikrotik/routeros-ssh.rsc`, замініть адреси і пароль, вставте команди (замість розділів «management service» і «dedicated account» з `routeros7.rsc` / `routeros6.rsc`; розділи L2TP/IPsec і firewall з них теж потрібні).
2. Вхід за ключем (рекомендовано): на сервері `ssh-keygen -t ed25519 -N "" -C vpn-gw -f vpn-gw-mikrotik` (для RouterOS 6 — `-t rsa -b 4096 -m PEM`), відкритий ключ — на маршрутизатор (`/user ssh-keys import`), приватний — у панель (**SSH: приватний ключ**), потім видаліть обидва файли.
3. Панель → **MikroTik** → «Редагувати» на картці маршрутизатора (або «+ Додати маршрутизатор»): протокол **SSH**, порт 22, користувач і пароль, «Зберегти». Вхід за ключем — спільний ключ у `.env` (`MT_SSH_KEY`).

В обох випадках правила **drop** створюються **вимкненими** — VPN працює як раніше.

Перевірка: панель → **MikroTik → «Перевірити MikroTik»**. Для кожного маршрутизатора має бути зелений рядок. Для SSH панель покаже і збереже ключ хоста — звірте його з адмінського ПК: `ssh-keyscan -p 22 <адреса> | ssh-keygen -lf -`. Повна перевірка з додаванням тестової адреси:
```bash
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php grant  198.51.100.99
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php find   198.51.100.99
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php revoke 198.51.100.99
```
**Очікувано:** `Granted until …`, на кожному маршрутизаторі `present`, після `revoke` — `Revoked`.

---

## 10. Фінальна перевірка

```bash
./install.sh --check --domain vpn-gw.company.ua
```
**Очікувано:** `FAIL: 0`.

| Перевірка | Де | Очікувано |
|---|---|---|
| `https://vpn-gw.company.ua/` | будь-де | 404 |
| `https://vpn-gw.company.ua/<шлях>/admin` | мобільний інтернет | 404 |
| `https://vpn-gw.company.ua/<шлях>/enroll` | офісний ПК | сторінка реєстрації, QR сканується Microsoft Authenticator |
| Вхід: логін → код | мобільний інтернет | «Доступ дозволено», IP у списку на всіх маршрутизаторах, лист на пошту |
| «Відкликати доступ» | там само | запис зник, VPN-сесія розірвана |
| Панель → Журнали | офісний ПК | події входу без паролів і кодів |
| Панель → Резервні копії → «Створити копію зараз» | офісний ПК | за кілька секунд «успішно», копія в списку |

Далі — пілот і увімкнення drop-правил: `INSTALL_GUIDE.md`, етапи 10–11. Інструкція для співробітників — `USER_GUIDE.md`.

---

## Оновлення до нової версії

**Через панель (рекомендовано):** **Оновлення** → виберіть `vpn-gw-X.Y.zip` і `vpn-gw-X.Y.zip.sha256` → «Перевірити й оновити». Далі все відбувається автоматично:
1. перевірка SHA-256, структури архіву і версії;
2. резервна копія;
3. знімок поточної версії;
4. запуск `install.sh`;
5. перевірка застосунку.

Якщо оновлення не вдалося, панель автоматично повертає попередню версію. Хід виконання видно на сторінці завдання.

**З командного рядка:**
```bash
cd /tmp && sha256sum -c vpn-gw-X.Y.zip.sha256
cd /root && unzip -q /tmp/vpn-gw-X.Y.zip && cd vpn-gw-X.Y
./install.sh --check --domain vpn-gw.company.ua
./install.sh --domain vpn-gw.company.ua --yes
```
В обох способах зберігаються `.env`, база даних, налаштування з панелі, адміністратори і сертифікати. Спершу оновлюйте тестовий стенд. Серверу потрібен доступ до packagist.org.

---

## Що де лежить після встановлення

| Що | Де |
|---|---|
| Код | `/srv/vpn-gw` (власник `root:vpngw`, для застосунку лише читання) |
| Ключі і базові налаштування | `/srv/vpn-gw/.env` (0600) |
| Налаштування з панелі, адміністратори, TOTP | `/var/lib/vpn-gw/db.sqlite` (секрети зашифровані `APP_KEY`) |
| Журнал аудиту / помилки PHP | `/var/lib/vpn-gw/logs/audit.log` (або каталог, вибраний у панелі), `php-error.log` |
| Резервні копії | `/var/backups/vpn-gw/*.vgbk` (зашифровані) |
| Стан завдань агента | `/var/lib/vpn-gw-agent/status/` |
| Параметри для агента | `/etc/vpn-gw/install.conf` |
| Журнали Apache | `/var/log/apache2/vpn-gw-*.log` |
| Віртуальний хост / пул FPM | `/etc/apache2/sites-available/vpn-gw.conf`, `/etc/php/8.4/fpm/pool.d/vpngw.conf` |
| Таймери і агент | `systemctl list-timers 'vpn-gw*'`, `systemctl status vpn-gw-agent.path` |

## Корисні команди

```bash
./install.sh --check --domain vpn-gw.company.ua                        # повна діагностика
sudo -u vpngw php /srv/vpn-gw/bin/admin.php list                       # адміністратори панелі
sudo -u vpngw php /srv/vpn-gw/bin/admin.php passwd admin               # новий пароль адміністратора
sudo -u vpngw php /srv/vpn-gw/bin/admin.php create petrenko            # ще один адміністратор
sudo -u vpngw php /srv/vpn-gw/bin/settings.php list                    # чинні налаштування і їх джерело
sudo -u vpngw php /srv/vpn-gw/bin/settings.php reset MT_LIST           # повернути значення з .env
sudo php /srv/vpn-gw/bin/agent.php backup                              # резервна копія зараз
sudo php /srv/vpn-gw/bin/restore.php /var/backups/vpn-gw/<файл>.vgbk   # вміст копії (--apply — відновити)
sudo -u vpngw php /srv/vpn-gw/bin/unlock.php --user ivanenko --by admin    # або панель → «Огляд» → «Розблокувати»
sudo -u vpngw php /srv/vpn-gw/bin/totp-reset.php --user ivanenko --by admin   # або панель → «Автентифікатори»
systemctl restart php8.4-fpm apache2
```

## Якщо щось пішло не так

| Симптом | Що робити |
|---|---|
| `composer install завершився з помилкою` | Немає доступу до packagist.org: перевірте DNS, проксі, вихідний 443 |
| `Config::load: …` | Повідомлення вказує параметр. Значення з пробілами в `.env` беріть у лапки. Якщо причина у значенні з панелі: `settings.php reset <KEY>` |
| Панель → 404 | ПК не входить у `INTERNAL_NETWORKS` (`.env`) |
| Панель: «Забагато невдалих спроб» | Зачекайте `LOCKOUT_SECONDS`; або `admin.php passwd admin` (знімає блокування логіна) і `unlock.php --ip <адреса ПК> --by <хто>` |
| Завдання «у черзі» більше хвилини | `systemctl status vpn-gw-agent.path vpn-gw-agent.service`, `journalctl -u vpn-gw-agent` |
| `LDAPS … сертифікат не довірений` | Не той CA у полі «Сертифікат CA» (AD) або DC має сертифікат іншого CA. Тимчасово — режим «без перевірки сертифіката» |
| MikroTik: «SSH host key is not confirmed» | Натисніть «Перевірити MikroTik» у панелі |
| MikroTik: «SSH host key CHANGED» | Маршрутизатор замінено / перевстановлено — або підміна. Перевірте, потім «скинути» ключ у панелі і «Перевірити MikroTik» |
| `install.sh --check` зупиняється після розширень PHP | Виправлено в 0.3 (Composer від root чекав відповіді) |
| MikroTik: «Failed to connect» / «Connection refused» | `/ip service` (порт, `address=` з IP сервера), firewall маршрутизатора, nftables сервера |
| MikroTik: помилка сертифіката | Перевипустити сертифікат з IP маршрутизатора в SAN |
| Портал → 500 | `/var/lib/vpn-gw/logs/php-error.log` |
| Портал → 503 | `systemctl status php8.4-fpm`; сокет `/run/php/php8.4-fpm-vpngw.sock` |
