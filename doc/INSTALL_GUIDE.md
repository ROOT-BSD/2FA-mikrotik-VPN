# VPN-GW: покрокова інструкція — від чистого сервера до робочого порталу

**Версія 4.0 · 21.11.2026 · для VPN-GW 0.10.3** · Веб-сервер: **Apache 2.4 (mpm_event) + PHP-FPM 8.4** · Супровідні документи: `QUICKSTART.md` (короткий порядок команд), `ADMIN_GUIDE.md` (панель адміністратора), `DEVELOPMENT.md` (будова коду), `USER_GUIDE.md` (для співробітників), `VPN-GW_Як_це_працює.docx` (огляд)

Інструкція веде від порожньої віртуальної машини до запущеного порталу: встановлення Debian, захист сервера, розгортання коду скриптом `install.sh`, налаштування в панелі адміністратора, сертифікати, MikroTik, перевірки, резервне копіювання, пілот і безпечне введення в експлуатацію. Кожен етап закінчується пунктом **«Перевірка»** — не переходьте далі, доки вона не пройдена.

Умовні значення, які треба замінити на свої:

| Значення в інструкції | Що це |
|---|---|
| `10.0.10.30` | Внутрішня IP сервера VPN-GW (DMZ) |
| `203.0.113.10` | Публічна IP, на яку опублікований портал |
| `vpn-gw.company.ua` | DNS-ім'я порталу |
| `/k7f3x` | Неочевидний шлях порталу (`install.sh` генерує випадковий) |
| `10.0.5.0/24` | Адмін-підмережа (звідки SSH) |
| `10.0.0.0/8` | Внутрішня мережа (реєстрація TOTP і панель адміністратора) |
| `dc01`, `dc02.company.local` | Контролери домену |
| `10.0.10.1` (v7), `10.0.20.1` (v6) | MikroTik |
| `10.0.10.40` | SMTP |
| `10.0.10.50` | syslog / SIEM |
| `ivanenko` | Тестовий доменний користувач |
| `198.51.100.99` | Тестова публічна IP для перевірок `mt-check` |

---

## Етап 0. Підготовка: що має бути до початку

- [ ] **Віртуальна машина** у DMZ: 2 vCPU, 2 ГБ RAM, 20 ГБ диска, 1 мережевий інтерфейс.
- [ ] **ISO Debian 13 «trixie»** (netinst, amd64) з офіційного сайту debian.org; перевірити SHA256.
- [ ] **IP-адреси й DNS**: внутрішня IP сервера, публічна IP/NAT для порту 443 (і 80, якщо Let's Encrypt), A-запис `vpn-gw.company.ua` (**без AAAA**).
- [ ] **Сертифікат корпоративного CA** (`company-ca.pem`) — для перевірки сертифікатів AD (LDAPS / StartTLS) і MikroTik (протокол API). Без нього можливі лише тимчасові режими: AD без перевірки сертифіката, MikroTik по SSH.
- [ ] **TLS-сертифікат порталу**: Let's Encrypt (публічний) або виданий комерційним CA.
- [ ] **Група AD** `VPN-Users` (і для пілоту — `VPN-Users-Pilot`), тестовий користувач.
- [ ] **Доступ адміністратора** до MikroTik (Winbox / SSH) і до міжмережевого екрана DMZ.
- [ ] **Тестовий стенд RouterOS**: MikroTik CHR 7.x і 6.x у віртуалках — для розробки без ризику для бойових маршрутизаторів.
- [ ] **Архів з кодом** `vpn-gw-0.10.3.zip` і файл контрольної суми `vpn-gw-0.10.3.zip.sha256`.
- [ ] **ПК адміністратора у внутрішній мережі** (підмережа входить в `INTERNAL_NETWORKS`): з нього відкривається панель адміністратора.
- [ ] **Сейф паролів** для `APP_KEY`, пароля адміністратора панелі і пароля резервних копій.
- [ ] Узгоджено зі службою безпеки: правила мережевого доступу (етап 2.6), строки зберігання журналів.

---

## Етап 1. Встановлення Debian 13

1. Завантажити ВМ з ISO → **Install** (текстовий інсталятор).
2. Мова: English (системні повідомлення англійською простіше гуглити), розташування: Ukraine, локаль: `en_US.UTF-8`, клавіатура: American English.
3. Мережа: якщо немає DHCP — вказати вручну IP `10.0.10.30/24`, шлюз, DNS-сервери (контролери домену).
4. Hostname: `vpn-gw`, domain: `company.local`.
5. **Пароль root — залишити порожнім.** Тоді інсталятор вимкне вхід root і додасть першого користувача в `sudo`.
6. Користувач: наприклад `sysadm`, складний пароль.
7. Часовий пояс: Kyiv.
8. Розмітка: *Guided – use entire disk and set up LVM*, «All files in one partition». (Для КІІ за вимогами політики можна винести `/var/log` окремо.)
9. Дзеркало: `deb.debian.org`.
10. **Software selection** — зняти все, крім:
    - [x] SSH server
    - [x] standard system utilities
    - [ ] Debian desktop environment, GNOME тощо — **не встановлювати**
11. GRUB — на основний диск. Перезавантаження.

**Перевірка:**
```bash
ssh sysadm@10.0.10.30
cat /etc/os-release | grep VERSION=      # VERSION="13 (trixie)"
sudo -v && echo sudo-ok
```

---

## Етап 2. Базове налаштування та захист сервера

### 2.1. Оновлення і базові пакети

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y vim curl unzip ca-certificates gnupg \
  chrony nftables unattended-upgrades apt-listchanges \
  ldap-utils openssl netcat-openbsd sqlite3 rsyslog logrotate
```

### 2.2. Статична IP (якщо не задана в інсталяторі)

`/etc/network/interfaces`:
```
auto ens18
iface ens18 inet static
    address 10.0.10.30/24
    gateway 10.0.10.354
    dns-nameservers 10.0.10.11 10.0.10.12
    dns-search company.local
```
```bash
sudo systemctl restart networking
```

### 2.3. Час — критично для TOTP

Коди TOTP прив'язані до часу: розбіжність понад 30–60 с = коди не проходять.

`/etc/chrony/sources.d/company.sources`:
```
server dc01.company.local iburst
server dc02.company.local iburst
```
```bash
sudo systemctl restart chrony
sudo timedatectl set-timezone Europe/Kyiv
chronyc tracking | grep -E "System time|Leap"    # зсув < 0.1 с, Leap status: Normal
```

### 2.4. Автоматичні оновлення безпеки

```bash
sudo dpkg-reconfigure -plow unattended-upgrades     # Yes
```
У `/etc/apt/apt.conf.d/50unattended-upgrades` залишити лише `origin=Debian,codename=${distro_codename}-security` і `...,label=Debian-Security`. Перезавантаження — вручну, у вікно обслуговування.

### 2.5. SSH: лише ключі, лише з адмін-підмережі

На своєму ПК: `ssh-keygen -t ed25519` → `ssh-copy-id sysadm@10.0.10.30`.

`/etc/ssh/sshd_config.d/10-hardening.conf`:
```
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AllowUsers sysadm
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
AllowTcpForwarding no
ClientAliveInterval 300
ClientAliveCountMax 2
```
```bash
sudo sshd -t && sudo systemctl reload ssh
```
> **Не закривайте поточну сесію**, доки в новому вікні не перевірите вхід за ключем.

### 2.6. Міжмережевий екран nftables

Вхідні: 443/80 — звідусіль, 22 — лише з адмін-підмережі. Вихідні: лише потрібні напрямки.

`/etc/nftables.conf` (той самий файл — `deploy/nftables/nftables.conf`):
```
#!/usr/sbin/nft -f
# /etc/nftables.conf for the VPN-GW server. Replace addresses with your own.
#
# What is dropped is logged: journalctl -k | grep -E 'nft-(in|out)-drop'
# DNS must go to the servers in /etc/resolv.conf (or `resolvectl status` with systemd-resolved),
# otherwise apt, composer and certbot cannot resolve names.
flush ruleset

define ADMIN_NET = 10.0.5.0/24
define DCS       = { 10.0.10.11, 10.0.10.12 }      # domain controllers (LDAP / LDAPS)
define DNS       = { 10.0.10.11, 10.0.10.12 }      # resolvers from /etc/resolv.conf
define ROUTERS   = { 10.0.10.1, 10.0.20.1 }
define SMTP      = 10.0.10.40
define SIEM      = 10.0.10.50
# HTTP proxy for apt / composer, if the server reaches the Internet only through it:
# define PROXY = 10.0.10.60   and uncomment the PROXY rule in chain output

table inet filter {
  set web_rl {
    type ipv4_addr; flags dynamic; timeout 1m;
  }
  chain input {
    type filter hook input priority 0; policy drop;
    ct state established,related accept
    ct state invalid drop
    iif lo accept
    ip protocol icmp icmp type { echo-request, destination-unreachable, time-exceeded } limit rate 10/second accept
    # IPv6 cannot work without neighbour discovery
    meta l4proto ipv6-icmp icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-advert, echo-request, destination-unreachable, packet-too-big, time-exceeded, parameter-problem } accept
    # at most 60 new web connections per minute per source IP
    tcp dport { 80, 443 } ct state new add @web_rl { ip saddr limit rate over 60/minute burst 30 packets } drop
    tcp dport { 80, 443 } accept
    ip saddr $ADMIN_NET tcp dport 22 accept
    log prefix "nft-in-drop " limit rate 5/minute
  }
  chain forward { type filter hook forward priority 0; policy drop; }
  chain output {
    type filter hook output priority 0; policy drop;
    ct state established,related accept
    oif lo accept
    ip protocol icmp icmp type { echo-request, destination-unreachable, time-exceeded } accept   # ping, traceroute replies
    meta l4proto ipv6-icmp accept                                                         # IPv6 neighbour discovery, ping
    ip daddr $DNS udp dport 53 accept                    # DNS
    ip daddr $DNS tcp dport 53 accept
    udp dport 123 accept                                 # NTP: systemd-timesyncd / chrony to any server
    udp dport 33434-33534 accept                         # traceroute (UDP); answers come back as ICMP
    ip daddr $DCS tcp dport { 389, 636 } accept          # LDAP/StartTLS, LDAPS
    ip daddr $ROUTERS tcp dport { 22, 443, 8443, 8729 } accept   # SSH, REST (v7), API-SSL (v6)
    ip daddr $SMTP tcp dport 587 accept
    ip daddr $SIEM tcp dport 6514 accept
    ip daddr $SIEM udp dport 514 accept                  # SIEM over UDP (LOG_REMOTE)
    # ip daddr $PROXY tcp dport { 3128, 8080 } accept    # apt / composer through a proxy
    tcp dport { 80, 443 } accept                         # apt, composer, Let's Encrypt (or only to your proxy)
    log prefix "nft-out-drop " limit rate 5/minute
  }
}
```
```bash
sudo nft -c -f /etc/nftables.conf && sudo systemctl enable --now nftables
```
> Правило з `@web_rl` обмежує кількість **нових з'єднань** з однієї IP (60/хв). В Apache немає вбудованого аналога `limit_req`, тому грубе обмеження робимо на рівні firewall, а точне (спроби входу і кодів) — у самому застосунку (`RateLimiter`).

> Правило `tcp dport {80,443}` у вихідних потрібне для `apt`, `composer` і Let's Encrypt. Якщо в організації є проксі/дзеркало репозиторіїв — замініть його на дозвіл лише до проксі (`define PROXY` і правило з ним).

> **DNS і NTP.** У `DNS` вкажіть **ті самі** сервери, що в `/etc/resolv.conf` (з systemd-resolved — `resolvectl status`). Якщо там інші адреси, ніж дозволені, `apt update` і `composer` не знайдуть жодного сервера (помилки «Temporary failure resolving …»), хоча порти 80/443 відкриті. NTP (UDP 123) дозволено до будь-якого сервера — systemd-timesyncd і chrony працюють з пулами за замовчуванням.

> **ping і traceroute назовні:** ping — правило `icmp type echo-request`, traceroute — UDP 33434–33534 (типовий режим `traceroute`; `traceroute -I` і `mtr` працюють через ICMP, `traceroute -T` — через TCP 80/443); відповіді проходять як пов'язані з'єднання. ICMPv6 потрібен для роботи IPv6 (виявлення сусідів). Що саме блокується, видно в журналі ядра: `journalctl -k | grep -E 'nft-(in|out)-drop'`.

### 2.7. Довіра до корпоративного CA

```bash
sudo cp company-ca.pem /usr/local/share/ca-certificates/company-ca.crt
sudo update-ca-certificates
sudo install -m 644 company-ca.pem /etc/ssl/company-ca.pem
echo "TLS_CACERT /etc/ssl/company-ca.pem" | sudo tee -a /etc/ldap/ldap.conf
```

### 2.8. Пересилання журналів у SIEM

`/etc/rsyslog.d/60-siem.conf`:
```
auth,authpriv.*  @@(o)10.0.10.50:6514
local5.*         @@(o)10.0.10.50:6514
```
(`local5` — типова facility журналу VPN-GW; змінюється в панелі: **Журнали → Facility syslog**.) Для TLS-пересилання встановити `rsyslog-gnutls` і налаштувати `$DefaultNetstreamDriver gtls` за інструкцією SIEM.

Альтернатива без rsyslog: панель → **Журнали → Віддалений syslog / SIEM** (`10.0.10.50:514`, UDP, RFC 5424). Недоступність SIEM не впливає на роботу порталу.
```bash
sudo systemctl restart rsyslog
logger -p local5.info "vpn-gw test event"      # має з'явитися в SIEM
```

**Перевірка етапу 2:**
```bash
sudo nft list ruleset | head
ssh -o PasswordAuthentication=yes sysadm@10.0.10.30     # має бути відмова
chronyc tracking | grep "System time"
```

---

## Етап 3. Отримати код

Скопіюйте на сервер архів і файл контрольної суми, наприклад у `/tmp`:
- з Windows — WinSCP (протокол SFTP) або `scp` у PowerShell;
- з Linux / macOS: `scp vpn-gw-0.10.3.zip vpn-gw-0.10.3.zip.sha256 admin@10.0.10.30:/tmp/`

На сервері перевірте цілісність і розпакуйте:
```bash
cd /tmp && sha256sum -c vpn-gw-0.10.3.zip.sha256          # має бути: vpn-gw-0.10.3.zip: OK
cd /root && sudo unzip -q /tmp/vpn-gw-0.10.3.zip && cd vpn-gw-0.10.3
```

**Перевірка:** `sha256sum` → `OK`; `cat VERSION` → `0.10.3`; у каталозі є `install.sh`, `composer.json`, `src/`, `deploy/`. Подальші команди `./install.sh` — з цього каталогу.

---

## Етап 4. Встановлення скриптом `install.sh`

### 4.1. Попередня перевірка (нічого не змінює)

```bash
sudo ./install.sh --check
```
Скрипт перевіряє ОС, диск, пам'ять, **синхронізацію часу**, пакети, PHP і 12 розширень, модулі Apache, `.env`, сервіси, сертифікати і зв'язок. Підсумок: `OK / WARN / FAIL`. На чистій системі FAIL щодо відсутніх пакетів і `.env` — очікувані.

### 4.2. Встановлення

```bash
sudo ./install.sh --domain vpn-gw.company.ua
```

Що відбувається (11 кроків):

| Крок | Дія |
|---|---|
| 1 | Попередні перевірки, підтвердження |
| 2 | Встановлення відсутніх пакетів (Apache, PHP-FPM з розширеннями, Composer, chrony, утиліти); видалення `mod_php` |
| 3 | PHP ≥ 8.2 і розширення `ldap curl sodium pdo_sqlite mbstring openssl sockets json ctype tokenizer xml dom` |
| 4 | Модулі Apache (`mpm_event proxy_fcgi rewrite headers ssl http2…`), `ServerTokens Prod`, вимкнення стандартних сайтів |
| 5 | Користувач `vpngw`; каталоги застосунку `/var/lib/vpn-gw/{locks,logs,sessions,tmp,spool}` (700, `vpngw`), `/var/log/vpn-gw`; каталоги агента `/var/lib/vpn-gw-agent`, `/var/backups/vpn-gw` (власник root, `vpngw` лише читає), `/etc/vpn-gw` |
| 6 | Копіювання коду, `composer install --no-dev`, `composer audit`; код переходить у власність `root:vpngw` (застосунок лише читає) |
| 7 | `.env`: якщо відсутній — створюється з **новими ключами** і випадковим шляхом порталу; перевірка прав, CA-файлів, `Config::load` (з урахуванням значень з панелі) |
| 8 | Міграція БД; **перший адміністратор панелі** (пароль запитується; з `--yes` — генерується); пароль шифрування резервних копій |
| 9 | Пул PHP-FPM, віртуальний хост (домен і шлях з `.env`), таймер очищення, **агент** `vpn-gw-agent` (path + timer), `/etc/vpn-gw/install.conf`, logrotate; `--with-nftables` — також firewall сервера |
| 10 | Зв'язок: AD у вибраному режимі (LDAPS / StartTLS — TLS і довіра до сертифіката), кожен MikroTik з `ROUTERS` (API — TLS і сертифікат; SSH — доступність і збіг ключа хоста), SMTP STARTTLS; DNS (A без AAAA) |
| 11 | Smoke-тест: `/` і `/index.php` → 404, шлях порталу і стилі (`bootstrap.min.css`, `app.css`) → 200, версія Apache прихована |

Скрипт ідемпотентний: наявні `.env`, база, адміністратори, налаштування з панелі й сертифікати не перезаписуються; його можна запускати після кожної зміни. Маршрутизатори він **не змінює**. Коди завершення: 0 — OK, 1 — є невдалі перевірки, 2 — перервано.

Логін першого адміністратора — `admin`, інший задається `--admin-user petrenko`. Згенеровані паролі (адміністратора з `--yes` і резервних копій) записуються у `/root/vpn-gw-credentials.txt` (0600).

> **Збережіть у сейфі паролів** `APP_KEY` з `/srv/vpn-gw/.env`, пароль адміністратора панелі і пароль резервних копій з `/root/vpn-gw-credentials.txt`, потім `shred -u /root/vpn-gw-credentials.txt`. Без `APP_KEY` усім доведеться заново реєструвати Microsoft Authenticator, а налаштування з панелі не розшифруються. Без пароля резервних копій їх неможливо відновити.

**Перевірка:** «Створено адміністратора …», «Агент (резервні копії, оновлення) очікує завдань». FAIL на цьому етапі стосуються лише ще не заповнених AD/MikroTik і сертифікатів (етапи 5–7).

---

## Етап 5. Налаштування: `.env` і панель адміністратора

Налаштування зберігаються у двох місцях:

| Де | Що | Чому там |
|---|---|---|
| `/srv/vpn-gw/.env` | ключі `APP_*`, `APP_BASE_PATH`, `TRUSTED_PROXIES`, `INTERNAL_NETWORKS`, ліміти `RATE_*`, `LOCKOUT_SECONDS`, шляхи БД | від них залежить доступ до самої панелі; зміна з веба могла б заблокувати адміністратора або послабити захист |
| Панель адміністратора | MikroTik, пошта, Active Directory, журнали, резервне копіювання | зручно змінювати і перевіряти; значення з панелі мають **пріоритет** над `.env` |

Усі параметри можна задати і в `.env`, як у версії 0.1. Повний перелік і джерело кожного значення показує команда `sudo -u vpngw php /srv/vpn-gw/bin/settings.php list`.

### 5.1. `.env`: доступ і ліміти

```bash
sudo -u vpngw nano /srv/vpn-gw/.env
```

| Параметр | Що вказати |
|---|---|
| `APP_BASE_PATH` | вже згенеровано (`/abc123`); можна залишити |
| `TRUSTED_PROXIES` | `127.0.0.1` (Apache без балансувальника) |
| `INTERNAL_NETWORKS` | офісні підмережі через кому, **разом з підмережею ПК адміністраторів**: звідси доступні реєстрація TOTP і панель |
| `RATE_LOGIN_USER` | **менше** за поріг блокування облікового запису в AD |

Після зміни `.env` запустіть `sudo ./install.sh --domain vpn-gw.company.ua --yes`. Скрипт перевірить конфігурацію і перезапустить PHP-FPM.

### 5.2. Панель адміністратора

З ПК у внутрішній мережі відкрийте `https://vpn-gw.company.ua/<шлях>/admin`. До етапу 6 браузер попереджатиме про самопідписаний сертифікат. Увійдіть обліковим записом з етапу 4. Докладно про кожен розділ — `ADMIN_GUIDE.md`.

| Розділ | Поля | Перевірка в панелі |
|---|---|---|
| **Active Directory** | режим підключення (LDAPS з перевіркою — рекомендовано; LDAPS / StartTLS без перевірки сертифіката; LDAP без шифрування — лише для стенда); `ldaps://dc01.company.local:636,…` або `ldap://…:389`; домен (`nerc.gov.ua`); назва групи `VPN-Users`; CA `/etc/ssl/company-ca.pem` (лише з перевіркою) | «Перевірити AD» тестовим користувачем: з'єднання у вибраному режимі, пароль, членство в групі |
| **Пошта** | SMTP-сервер, порт 587 (STARTTLS), користувач, пароль, відправник, CA, контакт ІТ-служби; резервний код на e-mail вимкніть, якщо пошта доступна з Інтернету за AD-паролем | «Надіслати» тестовий лист |
| **MikroTik** | таблиця маршрутизаторів (назва, адреса, версія, протокол **API** або **SSH**, порт); для API — користувач `vpn_portal`, пароль, CA; для SSH — користувач, пароль або приватний ключ; address-list, тривалість доступу. Параметри підключення зберігаються зашифрованими | «Перевірити MikroTik» (після етапу 7); для SSH — також підтвердження ключа хоста |
| **Журнали** | файл, строк зберігання, рівень, категорії, syslog, SIEM | перегляд журналу |
| **Резервні копії** | розклад, кількість копій, пароль шифрування | «Створити копію зараз» |

Перед збереженням панель перевіряє всю конфігурацію. Якщо значення некоректне, з'являється повідомлення, а попереднє значення залишається.

**Перевірка етапу 5:** `sudo ./install.sh --check --domain vpn-gw.company.ua`: крок 7 «Config::load: налаштування коректні», крок 10 «LDAPS … TLS і сертифікат OK» (або відповідний рядок для вибраного режиму; для режимів без перевірки — попередження WARN). У панелі «Перевірити AD» показує зелений результат.

---

## Етап 6. Сертифікати

1. **Корпоративний CA** — етап 2.7 (`/etc/ssl/company-ca.pem`). Без нього LDAPS, MikroTik і SMTP не пройдуть перевірку.
2. **TLS-сертифікат порталу.** До цього моменту `install.sh` підставляє тимчасовий самопідписаний.
   - Let's Encrypt (портал доступний з Інтернету на 80):
     ```bash
     sudo apt install -y certbot python3-certbot-apache
     sudo certbot --apache -d vpn-gw.company.ua --agree-tos -m it@company.ua
     ```
   - Сертифікат CA організації або комерційний: покласти в `/etc/ssl/vpn-gw/fullchain.pem` і `privkey.pem` (`chmod 600`, власник root) і запустити `install.sh` ще раз — він підставить їх у віртуальний хост.

**Перевірка:** `curl -sI https://vpn-gw.company.ua/<шлях>/` з іншої машини без `-k` — 200, без помилок сертифіката.

---

## Етап 7. Підготовка MikroTik

> **Спочатку — тестовий стенд CHR.** На бойовому маршрутизаторі всі зміни робіть у **Safe Mode** (Winbox: *Safe Mode*; термінал: `Ctrl+X`) — якщо зв'язок обірветься, зміни відкотяться.

Протокол керування вибирається для кожного маршрутизатора в панелі:

| | API (REST / API-SSL) | SSH |
|---|---|---|
| RouterOS | 7 — REST (`www-ssl`), 6 — API-SSL | 6 і 7 |
| Захист каналу | TLS, сертифікат від корпоративного CA (SAN = IP) | SSH, ключ хоста підтверджується в панелі |
| Вхід | пароль | пароль і/або ключ (ed25519 / RSA; RouterOS 6 — лише RSA) |
| Команди | структуровані запити (JSON / API-слова) | консольні команди; кожне значення перевіряється за білим списком |
| Скрипт | `routeros7.rsc` / `routeros6.rsc` | `routeros-ssh.rsc` + розділи L2TP/IPsec і firewall з `routeros7.rsc` / `routeros6.rsc` |
| Що виконати | 7.1, 7.2 | 7.2a |

### 7.1. Сертифікат для сервісу керування (протокол API)

На корпоративному CA видати сертифікат, у SAN якого — **IP-адреса маршрутизатора**. Завантажити на роутер (Files) і імпортувати:
```
/certificate import file-name=router-api.crt passphrase=""
/certificate import file-name=router-api.key passphrase="<пароль ключа>"
/certificate set [find common-name=<CN>] name=router-api
/certificate print          # прапорець K = є приватний ключ
```

### 7.2. Сервіс, обліковий запис, правила

Скрипти — у `deploy/mikrotik/`:
- `routeros7.rsc` — `www-ssl` на 8443 лише з IP сервера, група `read,write,rest-api`;
- `routeros6.rsc` — `api-ssl` на 8729 лише з IP сервера, група `read,write,api`.

Обидва: обліковий запис `vpn_portal` з прив'язкою до IP сервера, вимкнення `telnet/ftp/www/api`, `use-ipsec=required authentication=mschap2` на L2TP-сервері, правила accept для `Allowed_VPN_Users` і **вимкнені** drop-правила (вмикаються на етапі 11).

Замініть у скрипті IP сервера і пароль (`openssl rand -base64 33`), відкрийте термінал маршрутизатора в Safe Mode і вставте команди. Порядок правил: `/ip firewall filter print` — accept вище за drop і після `established,related`.

### 7.2a. Протокол SSH

1. Відкрийте `deploy/mikrotik/routeros-ssh.rsc`, замініть IP сервера, адмін-підмережу і пароль, вставте в термінал у Safe Mode:
   - сервіс `ssh` лише з IP сервера й адмін-підмережі, `strong-crypto=yes`;
   - група `vpn-portal` з policy `ssh,read,write`, користувач `vpn_portal` з прив'язкою до IP сервера;
   - firewall для порту 22.

   Розділи L2TP/IPsec і правила `Allowed_VPN_Users` візьміть з `routeros7.rsc` / `routeros6.rsc`.
2. **Вхід за ключем** (рекомендовано, замість пароля або разом з ним):
   ```bash
   ssh-keygen -t ed25519 -N "" -C vpn-gw -f vpn-gw-mikrotik                 # RouterOS 7
   ssh-keygen -t rsa -b 4096 -m PEM -N "" -C vpn-gw -f vpn-gw-mikrotik      # RouterOS 6
   ```
   Відкритий ключ `vpn-gw-mikrotik.pub` завантажте на маршрутизатор і виконайте `/user ssh-keys import public-key-file=vpn-gw-mikrotik.pub user=vpn_portal`. Вміст приватного ключа вставте в панель (**MikroTik → SSH: приватний ключ**). Потім видаліть обидва файли з ПК і з маршрутизатора.
3. Панель → **MikroTik** → «Редагувати» на картці маршрутизатора (або «+ Додати маршрутизатор»): протокол **SSH**, порт 22, користувач і пароль, «Зберегти». Вхід за ключем — спільний ключ у `.env` (`MT_SSH_KEY`, `MT_SSH_KEY_PASS`).
4. **Ключ хоста.** Натисніть «Перевірити MikroTik». Панель покаже відбиток `SHA256:…` і збереже його. Звірте відбиток з адмінського ПК: `ssh-keyscan -p 22 10.0.20.1 | ssh-keygen -lf -`. Поки ключ не підтверджено, портал до маршрутизатора не підключається. Якщо ключ зміниться, портал відмовить із «SSH host key CHANGED».

### 7.3. Перевірка

Панель → **MikroTik**: введіть паролі (API і/або SSH), «Зберегти», потім «Перевірити MikroTik». Для кожного маршрутизатора має бути зелений рядок: з'єднання, сертифікат або ключ хоста, доступ до address-list. Повна перевірка з сервера з додаванням тестової адреси:

```bash
sudo ./install.sh --check --domain vpn-gw.company.ua            # крок 10: "MikroTik … TLS і сертифікат OK"
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php grant  198.51.100.99
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php grant  198.51.100.99   # вдруге — оновлення, не дублікат
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php find   198.51.100.99
sudo -u vpngw php /srv/vpn-gw/bin/mt-check.php revoke 198.51.100.99
```
На маршрутизаторах: `/ip firewall address-list print where list=Allowed_VPN_Users` — після `grant` один запис з коментарем `User: mt-check via VPN-2FA`, після `revoke` — немає.

---

## Етап 8. Функціональні перевірки

| № | Перевірка | Як | Очікувано |
|---|---|---|---|
| 1 | Маскування | `curl -s -o /dev/null -w '%{http_code}' https://vpn-gw.company.ua/` | 404; також `/index.php`, `/<шлях>/admin` |
| 2 | Реєстрація | з офісного ПК відкрити `/<шлях>/enroll`, тестовий користувач | QR сканується Microsoft Authenticator, перший код приймається |
| 3 | `/enroll` ззовні | з мобільного інтернету відкрити `/<шлях>/enroll` | 404 |
| 4 | Повний вхід | з мобільного інтернету: логін → код | «Доступ дозволено», IP у списку на всіх маршрутизаторах, лист на пошту |
| 5 | Повтор коду | ввести той самий код ще раз у новій сесії | «Невірний код» |
| 6 | Блокування | 3 невірні коди | «Забагато спроб»; `bin/unlock.php` знімає |
| 7 | Відкликання | «Відкликати доступ» | запис зник, L2TP-сесія розірвана |
| 8 | Журнал | панель → Журнали; `tail /var/lib/vpn-gw/logs/audit.log`; SIEM | JSON-події з `app_version` і `category`, без паролів і кодів |
| 9 | Користувач поза групою | тестовий обліковий запис без `VPN-Users` | «Невірні облікові дані», у журналі `reason: not_allowed` |
| 10 | Панель ззовні | з мобільного інтернету відкрити `/<шлях>/admin` | 404 |
| 11 | Блокування адміністратора | 5 невірних паролів у панелі | «Забагато невдалих спроб», подія `admin_lockout` |
| 12 | Збереження з помилкою | панель → MikroTik, тривалість `10 годин` | «Не збережено…», портал працює далі |
| 13 | Резервна копія | панель → Резервні копії → «Створити копію зараз» | за кілька секунд «успішно», файл `.vgbk` у списку |

Автоматичні тести коду (на машині розробника): `composer install && composer test && composer stan`.

---

## Етап 9. Резервне копіювання

Резервні копії створює агент `vpn-gw-agent`. Він працює від root і запускається systemd. Розклад, кількість копій і пароль задаються в панелі: **Резервні копії → Розклад і пароль**.

**Склад копії:**
- база даних (налаштування з панелі, адміністратори, секрети TOTP; секрети зашифровані `APP_KEY`);
- `/srv/vpn-gw/.env`;
- `/etc/vpn-gw/install.conf`;
- TLS-сертифікати (`/etc/ssl/vpn-gw` або `/etc/letsencrypt`);
- файли CA;
- конфігурація Apache, PHP-FPM, nftables, logrotate.

Архів `*.vgbk` шифрується паролем (Argon2id + XChaCha20-Poly1305) і зберігається в `/var/backups/vpn-gw/`. Кількість копій обмежена налаштуванням `BACKUP_KEEP`.

| Дія | Як |
|---|---|
| Щоденна копія | автоматично о вибраній годині (`vpn-gw-agent.timer` щогодини перевіряє розклад) |
| Копія зараз | панель → «Створити копію зараз» або `sudo php /srv/vpn-gw/bin/agent.php backup` |
| Копія поза сервером | панель → «Завантажити» → покласти на захищене сховище; або забирати `/var/backups/vpn-gw/` засобами системи резервного копіювання |
| Переглянути вміст | `sudo php /srv/vpn-gw/bin/restore.php /var/backups/vpn-gw/<файл>.vgbk` |
| Відновити | `sudo php /srv/vpn-gw/bin/restore.php /var/backups/vpn-gw/<файл>.vgbk --apply` |

`restore.php` запитує пароль копії і повертає лише файли зі списку в копії. Поточну базу він зберігає як `db.sqlite.before-restore-<час>`, потім перезапускає PHP-FPM і Apache.

**Відновлення на новому сервері:**
1. Етапи 1–4 цієї інструкції з тією самою версією VPN-GW.
2. Скопіювати `.vgbk` на сервер і виконати `restore.php … --apply`.
3. Запустити `sudo ./install.sh --domain vpn-gw.company.ua --yes`.

> Пароль резервних копій зберігайте окремо від копій (сейф паролів). Без нього копію не відновити.

**Перевірка етапу 9:** у панелі з'явилася копія; `restore.php <файл>` показує її вміст. Раз на квартал виконуйте пробне відновлення на тестовому стенді.

---

## Етап 10. Пілот

1. Панель → **Active Directory** → група VPN тимчасово `VPN-Users-Pilot`, у групі 5–10 співробітників ІТ.
2. Пілотники реєструють Microsoft Authenticator в офісі та налаштовують VPN-клієнт за `USER_GUIDE.md` / `VPN-GW_Інструкція_користувача.docx`.
3. Тиждень користуються порталом **при вимкнених drop-правилах** на MikroTik: VPN працює як раніше, а портал паралельно наповнює `Allowed_VPN_Users`.
4. Щодня перевіряти: журнал (`mt_error`, `lockout`), чи всі підключення до VPN ідуть з IP, які є у списку:
```
/ppp active print
/ip firewall address-list print where list=Allowed_VPN_Users
```
5. Зібрати зауваження, виправити, повернути в панелі групу `VPN-Users` (або «повернути значення з .env»).

---

## Етап 11. Запуск і відкат

### 11.1. Запуск

1. Повідомити співробітників за 1–2 тижні: розіслати `VPN-GW_Інструкція_користувача.docx`, організувати реєстрацію Microsoft Authenticator в офісі.
2. Перевірити, що зареєстровані всі, кому потрібен VPN: панель → «Автентифікатори» або `sudo -u vpngw php /srv/vpn-gw/bin/totp-reset.php --count` проти кількості членів `VPN-Users`.
3. У погоджене вікно, в **Safe Mode**, увімкнути блокування на кожному маршрутизаторі:
```
/ip firewall filter enable [find comment="VPN: deny others"]
/ip firewall filter enable [find comment="VPN: ESP deny others"]
/interface l2tp-server server set use-ipsec=required authentication=mschap2
```
4. З зовнішньої мережі без проходження порталу — VPN **не** підключається; після порталу — підключається.
5. Вийти з Safe Mode (зберегти зміни).

### 11.2. Відкат (якщо щось пішло не так)

```
/ip firewall filter disable [find comment="VPN: deny others"]
/ip firewall filter disable [find comment="VPN: ESP deny others"]
```
VPN повертається до попереднього стану за секунди; портал можна виправляти без впливу на користувачів.

---

## Етап 12. Експлуатація

| Періодичність | Дія |
|---|---|
| Щодня (автоматично) | Оновлення безпеки Debian, ротація журналів, резервні копії, очищення БД |
| Щотижня | Переглянути в SIEM: серії `lockout`, `mt_error`, входи з незвичних країн |
| Щомісяця | `composer audit`; оновлення: на тестовому стенді, потім у продуктиві, через панель (**Оновлення**: архів + `.sha256`) або з командного рядка (`sha256sum -c` → розпакувати → `sudo ./install.sh --domain …`); перезавантаження сервера у вікно обслуговування |
| Щокварталу | Звірка `totp_secrets` з членами `VPN-Users` (видалити звільнених: панель → «Автентифікатори» або `bin/totp-reset.php`); перевірка відкату (11.2) на стенді; пробне відновлення резервної копії на стенді; перегляд списку адміністраторів панелі (`admin.php list`) |
| Щороку | Зміна паролю `vpn_portal` на MikroTik і SMTP (нові значення вводяться в панелі); зміна IPsec PSK; зміна паролів адміністраторів панелі; перевипуск сертифікатів; пентест |
| За подією | Звільнення адміністратора — `admin.php delete <логін>`, зміна PSK, паролів сервісних акаунтів і пароля резервних копій; втрата телефона співробітником — панель → «Автентифікатори» → «Видалити» (або `totp-reset`) |

### Типові проблеми

Перший крок у будь-якій ситуації — `sudo ./install.sh --check --domain vpn-gw.company.ua`: він перевіряє пакети, PHP, `.env`, сервіси, сертифікати і зв'язок з AD, MikroTik та SMTP.

| Симптом | Причина | Рішення |
|---|---|---|
| Коди TOTP не підходять у всіх | Збився час на сервері | `chronyc tracking`, перевірити доступ до NTP (UDP 123) |
| Коди не підходять в одного | Час на телефоні вручну | Увімкнути автоматичний час на телефоні |
| «Невірні облікові дані» при правильному паролі | Немає в `VPN-Users` / акаунт вимкнено / LDAPS недоступний | Журнал: поле `reason` |
| «Тимчасова помилка» | Маршрутизатор недоступний / сертифікат протермінований | Журнал `mt_error`; `sudo -u vpngw php bin/mt-check.php find <IP>`; розділ 10 `install.sh --check` |
| Портал пройдено, VPN не підключається | Різні мережі для порталу і VPN; IPv6 | Пройти портал з того самого пристрою/мережі |
| 503 Service Unavailable | PHP-FPM не запущено або немає доступу до сокета | `systemctl status php8.4-fpm`, `ls -l /run/php/php8.4-fpm-vpngw.sock`, `/var/log/apache2/vpn-gw-error.log` |
| 500 Internal Server Error | Помилка в `.env` (портал не стартує з некоректною конфігурацією) або в коді | `install.sh --check` (крок 7: Config::load), `/var/lib/vpn-gw/logs/php-error.log` |
| `install.sh`: «сертифікат не довірений» | CA-файл не той або в сертифікаті MikroTik немає IP у SAN | Перевипустити сертифікат; `openssl s_client -connect <IP>:8443 -CAfile …` |
| `.env`: «Failed to parse dotenv file» | Значення з пробілами або JSON без лапок | `IT_CONTACT="…"`, `ROUTERS='[…]'` |
| Браузер показує код PHP замість сторінки | Обробник FPM не спрацював | Перевірити `<Files "index.php">` у віртуальному хості, `apache2ctl -M` (має бути `proxy_fcgi_module`) |
| Панель → 404 | ПК адміністратора не в `INTERNAL_NETWORKS` | Додати підмережу в `.env`, `install.sh --yes` |
| Вхід на портал: «Невірні облікові дані», у журналі `login_fail reason=bad_ip` | Клієнт має приватну IP (вхід з офісної мережі), а портал за замовчуванням приймає лише публічні | Панель → MikroTik → «Дозволені приватні мережі клієнтів», наприклад `172.20.2.0/23` |
| MikroTik (SSH): «host key is not confirmed» | Ключ хоста ще не збережено | Панель → MikroTik → «Перевірити MikroTik» |
| MikroTik (SSH): «host key CHANGED» | Маршрутизатор замінено / RouterOS перевстановлено — або підміна | Перевірити відбиток (ssh-keyscan, потім ssh-keygen -lf), у панелі «скинути» ключ, «Перевірити MikroTik» |
| MikroTik (SSH): «login failed» | Користувач, пароль, ключ; RouterOS 6 не приймає ed25519 | Перевірити `/user ssh-keys print`, тип ключа |
| AD: «не вдалося встановити захищене з'єднання» у режимі StartTLS | DC не підтримує StartTLS на 389 або сертифікат не довірений | Режим LDAPS або «без перевірки сертифіката» (тимчасово) |
| `install.sh --check` зупиняється після «ext-dom» | Composer від root чекав відповіді (до 0.3) | Оновитися до 0.3 |
| Забули пароль адміністратора | — | `sudo -u vpngw php /srv/vpn-gw/bin/admin.php passwd admin` |
| Після збереження в панелі портал → 500 | Значення пройшло перевірку, але не працює в цьому середовищі (рідко) | `sudo -u vpngw php bin/settings.php list`, потім `settings.php reset <KEY>` — діятиме значення з `.env` |
| Завдання (копія, оновлення) «у черзі» | Агент не запущено | `systemctl status vpn-gw-agent.path`, `journalctl -u vpn-gw-agent -n 50`; `install.sh` ще раз |
| Оновлення через панель — «виконано відкат» | `install.sh` нової версії завершився помилкою або застосунок не стартував | Журнал на сторінці завдання; невдала версія — у `/srv/vpn-gw.failed-<id>` (видалити після аналізу) |
| Резервна копія — помилка | Не задано пароль копій / немає місця | Повідомлення на сторінці завдання; `df -h /var/backups` |

---

## Додаток A. Ручне встановлення (що робить `install.sh`)

Для середовищ, де скрипт запускати не можна:

```bash
sudo apt install -y apache2 ssl-cert composer chrony sqlite3 ldap-utils \
  php8.4-fpm php8.4-cli php8.4-ldap php8.4-curl php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-intl php8.4-opcache
sudo a2dismod -f mpm_prefork; sudo a2enmod mpm_event proxy proxy_fcgi setenvif rewrite headers ssl http2 alias reqtimeout
sudo a2dismod -f status autoindex; sudo a2dissite 000-default default-ssl
# security.conf: ServerTokens Prod, ServerSignature Off, TraceEnable Off
sudo adduser --system --group --home /srv/vpn-gw --shell /usr/sbin/nologin vpngw
sudo install -d -o vpngw -g vpngw -m 700 /var/lib/vpn-gw /var/lib/vpn-gw/{locks,logs,sessions,tmp,spool}
sudo install -d -o vpngw -g vpngw -m 750 /var/log/vpn-gw
sudo install -d -o root -g vpngw -m 750 /var/lib/vpn-gw-agent /var/lib/vpn-gw-agent/status /var/backups/vpn-gw
sudo install -d -m 700 /var/lib/vpn-gw-agent/work; sudo install -d -m 755 /etc/vpn-gw
sudo cp -a . /srv/vpn-gw && sudo chown -R vpngw:vpngw /srv/vpn-gw
sudo -u vpngw composer install -d /srv/vpn-gw --no-dev --classmap-authoritative
sudo chown -R root:vpngw /srv/vpn-gw && sudo chmod -R g-w,o-rwx /srv/vpn-gw && sudo chmod o+x /srv/vpn-gw && sudo chmod -R o+rX /srv/vpn-gw/public
sudo -u vpngw cp /srv/vpn-gw/.env.example /srv/vpn-gw/.env && sudo chmod 600 /srv/vpn-gw/.env   # заповнити, ключі:
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
sudo chown vpngw:vpngw /srv/vpn-gw/.env
sudo -u vpngw php /srv/vpn-gw/bin/migrate.php
sudo -u vpngw php /srv/vpn-gw/bin/admin.php create admin                 # пароль адміністратора панелі
sudo -u vpngw php /srv/vpn-gw/bin/settings.php init-backup-passphrase    # збережіть виведений пароль
printf '%s\n' DOMAIN=vpn-gw.company.ua TARGET=/srv/vpn-gw DATA_DIR=/var/lib/vpn-gw AGENT_DIR=/var/lib/vpn-gw-agent \
  BACKUP_DIR=/var/backups/vpn-gw APP_USER=vpngw PHPV=8.4 | sudo tee /etc/vpn-gw/install.conf
sudo cp /srv/vpn-gw/deploy/php-fpm/vpngw.conf /etc/php/8.4/fpm/pool.d/ && sudo mv /etc/php/8.4/fpm/pool.d/www.conf{,.disabled}
sudo cp /srv/vpn-gw/deploy/apache/vpn-gw.conf /etc/apache2/sites-available/   # домен, шлях, сертифікати
sudo a2ensite vpn-gw
sudo cp /srv/vpn-gw/deploy/systemd/vpn-gw-{cleanup,agent}.* /etc/systemd/system/ && sudo systemctl daemon-reload
sudo systemctl enable --now vpn-gw-cleanup.timer vpn-gw-agent.timer vpn-gw-agent.path
sudo cp /srv/vpn-gw/deploy/logrotate/vpn-gw /etc/logrotate.d/
sudo php-fpm8.4 -t && sudo systemctl restart php8.4-fpm
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Не встановлюйте `libapache2-mod-php` і не вмикайте `a2enconf php8.4-fpm`: цей конфіг віддає всі `.php` стандартному пулу.

---

## Додаток B. Підсумковий чек-лист

- [ ] Етапи 1–2: Debian 13, SSH лише за ключами, nftables, chrony синхронізовано, журнали йдуть у SIEM, корпоративний CA встановлено
- [ ] Етап 3: архів версії 0.3 скопійовано, контрольна сума збігається
- [ ] Етап 4: `install.sh` виконано; `APP_KEY`, пароль адміністратора панелі і пароль резервних копій — у сейфі, `/root/vpn-gw-credentials.txt` видалено
- [ ] Етап 5: `INTERNAL_NETWORKS` містить підмережу адміністраторів; AD і пошта налаштовані в панелі, перевірки — зелені
- [ ] Етап 6: TLS-сертифікат порталу дійсний
- [ ] Етап 7: MikroTik 6 і 7 — сервіс (API або SSH), користувач, правила (drop вимкнено); для SSH ключ хоста підтверджено й звірено; «Перевірити MikroTik» і `mt-check` grant/revoke — OK
- [ ] Етап 8: усі 13 функціональних перевірок пройдено
- [ ] Етап 9: щоденні копії створюються, копія поза сервером, пробне відновлення виконано
- [ ] Етап 10: пілот завершено без критичних зауважень
- [ ] Етап 11: drop-правила увімкнено, відкат перевірено
- [ ] Етап 12: регламент експлуатації погоджено зі службою безпеки
