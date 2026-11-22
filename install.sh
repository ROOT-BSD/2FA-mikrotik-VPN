#!/usr/bin/env bash
# =============================================================================
# VPN-GW installer for a fresh Debian 13 (trixie) / Debian 12 (bookworm) server.
#
#   sudo ./install.sh --check                      # only verify, change nothing
#   sudo ./install.sh --domain vpn-gw.company.ua   # install / update
#
# Options:
#   --check              preflight + dependency + connectivity checks only
#   --domain NAME        public DNS name of the portal (required for install)
#   --target DIR         install directory (default /srv/vpn-gw)
#   --with-nftables      also install deploy/nftables/nftables.conf (EDIT IT FIRST!)
#   --admin-user NAME    login of the first admin-panel account (default: admin)
#   --yes                do not ask for confirmation (admin password is then generated)
#   --version            print the version being installed
#   -h, --help
#
# Safe to run repeatedly: existing .env, database, admin accounts and certificates are kept.
# The script never enables MikroTik drop rules and never touches the routers.
# Exit codes: 0 = OK, 1 = finished with failed checks, 2 = aborted.
# =============================================================================
set -Eeuo pipefail

# ---------------------------------------------------------------- settings ---
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_VERSION="$(tr -d '[:space:]' < "$SRC_DIR/VERSION" 2>/dev/null || echo unknown)"
TARGET="/srv/vpn-gw"
DATA_DIR="/var/lib/vpn-gw"
AGENT_DIR="/var/lib/vpn-gw-agent"
BACKUP_DIR="/var/backups/vpn-gw"
LOG_DIR="/var/log/vpn-gw"
CONF_DIR="/etc/vpn-gw"
CRED_FILE="/root/vpn-gw-credentials.txt"
APP_USER="vpngw"
ADMIN_USER="admin"
DOMAIN=""
CHECK_ONLY=0
WITH_NFT=0
ASSUME_YES=0
PHP_MIN="8.2"
PHP_EXTS=(ldap curl sodium pdo_sqlite mbstring openssl sockets json ctype tokenizer xml dom gmp)

# ------------------------------------------------------------------ output ---
if [[ -t 1 ]]; then R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[1m'; N=$'\e[0m'; else R=; G=; Y=; B=; N=; fi
PASS=0; WARN=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  %s[ OK ]%s %s\n' "$G" "$N" "$*"; }
warn() { WARN=$((WARN+1)); printf '  %s[WARN]%s %s\n' "$Y" "$N" "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  %s[FAIL]%s %s\n' "$R" "$N" "$*"; }
step() { printf '\n%s== %s%s\n' "$B" "$*" "$N"; }
info() { printf '         %s\n' "$*"; }
die()  { printf '%s[FATAL]%s %s\n' "$R" "$N" "$*" >&2; exit 2; }
trap 'die "line $LINENO: command failed: $BASH_COMMAND"' ERR

usage() { sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --check) CHECK_ONLY=1 ;;
    --domain) DOMAIN="${2:-}"; shift ;;
    --target) TARGET="${2:-}"; shift ;;
    --with-nftables) WITH_NFT=1 ;;
    --admin-user) ADMIN_USER="${2:-}"; shift ;;
    --yes|-y) ASSUME_YES=1 ;;
    --version) echo "VPN-GW installer, version $APP_VERSION"; exit 0 ;;
    -h|--help) usage ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
  shift
done

run() { if [[ $CHECK_ONLY -eq 1 ]]; then info "(check) would run: $*"; else "$@"; fi; }
have() { command -v "$1" >/dev/null 2>&1; }
pkg_installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"; }

EFF=""; PLACEHOLDERS=0
# Effective value (admin panel overrides .env) once Config::load worked, else the .env value.
cfg_get() {
  local line
  line="$(grep -E "^$1=" <<<"$EFF" | head -n1 || true)"
  if [[ -n "$line" ]]; then printf '%s' "${line#*=}"; else env_get "$TARGET/.env" "$1"; fi
}

# Reads KEY from an env file (handles 'single', "double" and bare values).
env_get() {
  local file="$1" key="$2" line val
  line="$(grep -E "^${key}=" "$file" 2>/dev/null | tail -n1 || true)"
  val="${line#*=}"
  val="${val%$'\r'}"
  if [[ "$val" == \'*\' ]]; then val="${val:1:${#val}-2}"; elif [[ "$val" == \"*\" ]]; then val="${val:1:${#val}-2}"; fi
  printf '%s' "$val"
}

# ============================================================== 1. preflight ==
printf '%sVPN-GW %s — %s%s\n' "$B" "$APP_VERSION" "$([[ $CHECK_ONLY -eq 1 ]] && echo 'перевірка' || echo 'встановлення')" "$N"
step "1. Попередні перевірки"
[[ $EUID -eq 0 ]] || die "запустіть від root: sudo $0 $*"
[[ -f "$SRC_DIR/composer.json" && -d "$SRC_DIR/src" ]] || die "скрипт має лежати в корені проєкту VPN-GW ($SRC_DIR)"
if [[ $CHECK_ONLY -eq 0 && -z "$DOMAIN" ]]; then die "вкажіть --domain vpn-gw.company.ua (або --check)"; fi
[[ -z "$DOMAIN" || "$DOMAIN" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || die "некоректне ім'я домену: $DOMAIN"
[[ "$ADMIN_USER" =~ ^[a-z0-9][a-z0-9._-]{1,31}$ ]] || die "логін адміністратора: 2–32 символи a-z 0-9 . _ -"

. /etc/os-release 2>/dev/null || die "не вдалося визначити ОС"
case "${ID:-}:${VERSION_CODENAME:-}" in
  debian:trixie)   PHPV="8.4"; ok "ОС: Debian 13 (trixie)";;
  debian:bookworm) PHPV="8.2"; warn "ОС: Debian 12 (bookworm) — буде PHP 8.2; рекомендовано Debian 13";;
  *) PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.4)"
     warn "ОС ${PRETTY_NAME:-невідома} не тестувалась; припускаю PHP $PHPV";;
esac
[[ "$(uname -m)" =~ ^(x86_64|aarch64)$ ]] && ok "Архітектура: $(uname -m)" || warn "Архітектура $(uname -m) не тестувалась"

free_mb=$(df -Pm / | awk 'NR==2{print $4}')
(( free_mb >= 1024 )) && ok "Вільне місце: ${free_mb} МБ" || bad "Мало місця на диску: ${free_mb} МБ (потрібно ≥ 1 ГБ)"
mem_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
(( mem_mb >= 900 )) && ok "Пам'ять: ${mem_mb} МБ" || warn "Пам'ять ${mem_mb} МБ (рекомендовано ≥ 2 ГБ)"

if have timedatectl && [[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" == "yes" ]]; then
  ok "Час синхронізовано (критично для TOTP)"
else
  bad "Час НЕ синхронізовано — коди TOTP не працюватимуть. Налаштуйте chrony (INSTALL_GUIDE, етап 2.3)"
fi

if [[ $CHECK_ONLY -eq 0 && $ASSUME_YES -eq 0 ]]; then
  printf '\nВстановити VPN-GW %s у %s для домену %s? [y/N] ' "$APP_VERSION" "$TARGET" "$DOMAIN"
  read -r answer; [[ "$answer" =~ ^[YyТт]$ ]] || die "скасовано"
fi

# =============================================================== 2. packages ==
step "2. Системні пакети"
PKGS=(apache2 ssl-cert ca-certificates curl openssl unzip git sudo sqlite3 chrony ldap-utils netcat-openbsd openssh-client composer
      "php${PHPV}-fpm" "php${PHPV}-cli" "php${PHPV}-ldap" "php${PHPV}-curl" "php${PHPV}-sqlite3"
      "php${PHPV}-mbstring" "php${PHPV}-xml" "php${PHPV}-intl" "php${PHPV}-opcache" "php${PHPV}-gmp")
MISSING=()
for p in "${PKGS[@]}"; do pkg_installed "$p" || MISSING+=("$p"); done
if [[ ${#MISSING[@]} -eq 0 ]]; then
  ok "Усі ${#PKGS[@]} пакетів встановлено"
elif [[ $CHECK_ONLY -eq 1 ]]; then
  bad "Не встановлено: ${MISSING[*]}"
else
  info "Встановлюю: ${MISSING[*]}"
  DEBIAN_FRONTEND=noninteractive apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends "${MISSING[@]}" >/dev/null
  ok "Пакети встановлено"
fi

# mod_php must not be present: it forces mpm_prefork and runs PHP as www-data
MODPHP=$(dpkg-query -W -f='${Package}\n' 'libapache2-mod-php*' 2>/dev/null | while read -r p; do pkg_installed "$p" && echo "$p"; done || true)
if [[ -n "$MODPHP" ]]; then
  if [[ $CHECK_ONLY -eq 1 ]]; then bad "Встановлено mod_php ($MODPHP) — його треба видалити"
  else DEBIAN_FRONTEND=noninteractive apt-get purge -y -qq $MODPHP >/dev/null; ok "Видалено mod_php ($MODPHP)"; fi
else
  ok "mod_php відсутній (PHP працює через PHP-FPM)"
fi

# ==================================================================== 3. PHP ==
step "3. PHP і розширення"
FAIL_BEFORE_PHP=$FAIL
if have php; then
  cur="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  if php -r "exit(version_compare(PHP_VERSION, '$PHP_MIN', '>=') ? 0 : 1);"; then ok "PHP $cur (мінімум $PHP_MIN)"; else bad "PHP $cur < $PHP_MIN"; fi
  mods="$(php -m 2>/dev/null | tr '[:upper:]' '[:lower:]')"
  for e in "${PHP_EXTS[@]}"; do
    grep -qx "$e" <<<"$mods" && ok "ext-$e" || bad "Немає розширення PHP: ext-$e"
  done
else
  bad "PHP CLI не встановлено"
fi
if have composer; then ok "Composer $(COMPOSER_ALLOW_SUPERUSER=1 composer --no-interaction --version </dev/null 2>/dev/null | awk '{print $3}')"; else bad "Composer не встановлено"; fi
if [[ $FAIL -gt $FAIL_BEFORE_PHP && $CHECK_ONLY -eq 0 ]]; then die "є критичні проблеми з PHP — встановлення зупинено"; fi

# ================================================================= 4. Apache ==
step "4. Apache 2.4 + PHP-FPM"
if have a2enmod; then
  run a2dismod -q -f mpm_prefork mpm_worker >/dev/null 2>&1 || true
  run a2enmod -q mpm_event proxy proxy_fcgi setenvif rewrite headers ssl http2 alias reqtimeout >/dev/null
  run a2dismod -q -f status autoindex >/dev/null 2>&1 || true
  run a2disconf -q "php${PHPV}-fpm" >/dev/null 2>&1 || true
  run a2dissite -q 000-default default-ssl >/dev/null 2>&1 || true
  SEC=/etc/apache2/conf-available/security.conf
  if [[ $CHECK_ONLY -eq 0 && -f $SEC ]]; then
    sed -i -E 's/^\s*ServerTokens .*/ServerTokens Prod/; s/^\s*ServerSignature .*/ServerSignature Off/; s/^\s*TraceEnable .*/TraceEnable Off/' "$SEC"
  fi
  if [[ $CHECK_ONLY -eq 0 ]] || apache2ctl -M 2>/dev/null | grep -q proxy_fcgi; then
    for m in mpm_event proxy_fcgi rewrite headers ssl; do
      apache2ctl -M 2>/dev/null | grep -q "${m}_module" && ok "mod_$m" || bad "mod_$m не увімкнено"
    done
  else
    warn "Модулі Apache ще не налаштовано"
  fi
else
  bad "Apache не встановлено"
fi

# ======================================================= 5. user & directories ==
step "5. Користувач і каталоги"
if id "$APP_USER" >/dev/null 2>&1; then ok "Користувач $APP_USER існує"
elif [[ $CHECK_ONLY -eq 1 ]]; then warn "Користувача $APP_USER ще немає (буде створено)"
else adduser --system --group --home "$TARGET" --shell /usr/sbin/nologin "$APP_USER" >/dev/null; ok "Створено користувача $APP_USER"; fi
# application data: owned by the app user
for d in "$DATA_DIR" "$DATA_DIR/locks" "$DATA_DIR/logs" "$DATA_DIR/sessions" "$DATA_DIR/tmp" "$DATA_DIR/spool"; do
  if [[ $CHECK_ONLY -eq 0 ]]; then install -d -o "$APP_USER" -g "$APP_USER" -m 700 "$d"; fi
  [[ -d "$d" ]] && ok "$d" || warn "$d ще не створено"
done
if [[ $CHECK_ONLY -eq 0 ]]; then install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$LOG_DIR"; fi
# agent (root) data: owned by root; the web may only read job status and backups
for spec in "$AGENT_DIR:root:$APP_USER:750" "$AGENT_DIR/status:root:$APP_USER:750" "$AGENT_DIR/work:root:root:700" \
            "$BACKUP_DIR:root:$APP_USER:750" "$CONF_DIR:root:root:755"; do
  IFS=: read -r d o g m <<<"$spec"
  if [[ $CHECK_ONLY -eq 0 ]]; then install -d -o "$o" -g "$g" -m "$m" "$d"; chown "$o:$g" "$d"; chmod "$m" "$d"; fi
  [[ -d "$d" ]] && ok "$d ($o:$g $m)" || warn "$d ще не створено"
done

# ================================================================ 6. code ==
step "6. Код застосунку"
OLD_VERSION="$(tr -d '[:space:]' < "$TARGET/VERSION" 2>/dev/null || true)"
if [[ -n "$OLD_VERSION" && "$OLD_VERSION" != "$APP_VERSION" ]]; then info "Оновлення: $OLD_VERSION -> $APP_VERSION"
elif [[ -n "$OLD_VERSION" ]]; then info "Встановлена версія: $OLD_VERSION"; fi
if [[ $CHECK_ONLY -eq 0 ]]; then
  if [[ "$SRC_DIR" != "$TARGET" ]]; then
    tar -C "$SRC_DIR" --exclude=./vendor --exclude=./.git --exclude=./.env --exclude=./.phpunit.cache -cf - . | tar -C "$TARGET" -xf -
    ok "Скопійовано $SRC_DIR -> $TARGET"
  else
    ok "Код уже в $TARGET"
  fi
  install -d -m 750 "$TARGET"
  chown -R "$APP_USER:$APP_USER" "$TARGET"   # composer runs as the app user
  info "composer install (це може зайняти хвилину)..."
  COMPOSER_CMD=(sudo -u "$APP_USER" -H env COMPOSER_HOME="$DATA_DIR/.composer" composer)
  COMPOSER_OPTS=(-d "$TARGET" --no-dev --classmap-authoritative --no-interaction --no-progress)
  COMPOSER_LOG="$(mktemp)"
  if "${COMPOSER_CMD[@]}" install "${COMPOSER_OPTS[@]}" >"$COMPOSER_LOG" 2>&1; then
    ok "Залежності встановлено"
  elif grep -qiE 'lock file|not present in the lock' "$COMPOSER_LOG"; then
    # composer.lock was created on this server by the previous version; the new release
    # added or changed dependencies in composer.json, so the lock file has to be refreshed.
    info "composer.json нової версії змінився — оновлюю composer.lock (composer update)..."
    cp -a "$TARGET/composer.lock" "$TARGET/composer.lock.bak" 2>/dev/null || true
    if "${COMPOSER_CMD[@]}" update "${COMPOSER_OPTS[@]}" --with-all-dependencies >"$COMPOSER_LOG" 2>&1; then
      ok "Залежності оновлено (попередній lock-файл: composer.lock.bak)"
    else
      tail -n 15 "$COMPOSER_LOG" >&2
      die "composer update завершився з помилкою (перевірте доступ до packagist.org або проксі)"
    fi
  else
    tail -n 15 "$COMPOSER_LOG" >&2
    die "composer install завершився з помилкою (перевірте доступ до packagist.org або проксі)"
  fi
  rm -f "$COMPOSER_LOG"
  if sudo -u "$APP_USER" -H env COMPOSER_HOME="$DATA_DIR/.composer" composer audit -d "$TARGET" --no-dev --no-interaction >/dev/null 2>&1; then
    ok "composer audit: вразливостей не знайдено"
  else
    warn "composer audit знайшов проблеми: sudo -u $APP_USER composer audit -d $TARGET"
  fi
  # Code is owned by root and read-only for the app user: the root agent runs it, so a
  # compromised web process must not be able to change it. Apache (others) may only
  # traverse to public/assets.
  chown -R "root:$APP_USER" "$TARGET"
  find "$TARGET" -path "$TARGET/.env" -prune -o -type d -exec chmod 750 {} + -o -type f -exec chmod 640 {} +
  chmod 751 "$TARGET"; chmod 755 "$TARGET/public" "$TARGET/public/assets"; chmod 644 "$TARGET"/public/assets/*
  chmod 750 "$TARGET/install.sh"
  if [[ -f "$TARGET/.env" ]]; then chown "$APP_USER:$APP_USER" "$TARGET/.env"; fi
  ok "Права на код: root:$APP_USER, для $APP_USER лише читання"
  if [[ -f "$TARGET/doc/VPN-GW_Інструкція_підключення.pdf" ]]; then ok "Пам'ятка для користувачів (PDF) на місці"; else warn "Немає $TARGET/doc/VPN-GW_Інструкція_підключення.pdf — після реєстрації автентифікатора пам'ятку не буде надіслано"; fi
else
  [[ -f "$TARGET/vendor/autoload.php" ]] && ok "vendor/ на місці" || warn "Залежності ще не встановлено ($TARGET/vendor)"
fi

# ================================================================== 7. .env ==
step "7. Конфігурація (.env)"
ENV="$TARGET/.env"
NEED_EDIT=0
if [[ ! -f "$ENV" ]]; then
  if [[ $CHECK_ONLY -eq 1 ]]; then
    bad "$ENV відсутній"
  else
    k1="base64:$(head -c 32 /dev/urandom | base64 -w0)"; k2="base64:$(head -c 32 /dev/urandom | base64 -w0)"
    base="/$(head -c 64 /dev/urandom | tr -dc 'a-z0-9' | head -c 6)"
    sed -e "s|^APP_KEY=base64:$|APP_KEY=$k1|" -e "s|^APP_HMAC_KEY=base64:$|APP_HMAC_KEY=$k2|" \
        -e "s|^APP_BASE_PATH=.*|APP_BASE_PATH=$base|" "$TARGET/.env.example" > "$ENV"
    chown "$APP_USER:$APP_USER" "$ENV"; chmod 600 "$ENV"
    ok "Створено $ENV з новими ключами і шляхом порталу $base"
    warn "ЗБЕРЕЖІТЬ копію APP_KEY у сейфі паролів: без нього всім доведеться реєструватися заново"
    NEED_EDIT=1
  fi
fi
if [[ -f "$ENV" ]]; then
  perm="$(stat -c '%a %U' "$ENV")"
  [[ "$perm" == "600 $APP_USER" ]] && ok ".env: права 600, власник $APP_USER" || { bad ".env має права/власника '$perm' (потрібно 600 $APP_USER)"; run chmod 600 "$ENV"; run chown "$APP_USER:$APP_USER" "$ENV"; }
  # Effective settings = .env + values saved in the admin panel
  if [[ -f "$TARGET/vendor/autoload.php" ]]; then
    as_app=(); id "$APP_USER" >/dev/null 2>&1 && as_app=(sudo -u "$APP_USER")
    if out="$(cd "$TARGET" && "${as_app[@]}" php "$TARGET/bin/settings.php" effective 2>&1)" && grep -q '^DB_PATH=' <<<"$out"; then
      ok "Config::load: налаштування коректні"
      EFF="$out"
    else
      bad "Помилка конфігурації: ${out##*: }"; NEED_EDIT=1
    fi
  else
    NEED_EDIT=1
  fi
  for k in LDAP_HOSTS LDAP_DOMAIN LDAP_VPN_GROUP; do
    v="$(cfg_get "$k")"
    if [[ -z "$v" || "$v" == *company.local* ]]; then warn "$k: заповніть реальним значенням (панель адміністратора або .env)"; PLACEHOLDERS=1; fi
  done
  LDAP_MODE="$(cfg_get LDAP_SECURITY)"; LDAP_MODE="${LDAP_MODE:-ldaps}"
  case "$LDAP_MODE" in
    ldaps|starttls) f="$(cfg_get LDAP_CA_FILE)"; [[ -s "$f" ]] && ok "LDAP_CA_FILE: $f" || bad "LDAP_CA_FILE: файл '$f' не знайдено (сертифікат корпоративного CA)" ;;
    plain) warn "AD: режим без шифрування (LDAP_SECURITY=plain) — паролі передаються мережею відкритим текстом" ;;
    *) warn "AD: сертифікат контролера домену не перевіряється (LDAP_SECURITY=$LDAP_MODE)" ;;
  esac
  if php -r '$r=json_decode($argv[1],true); foreach((array)$r as $x) if(($x["transport"]??"api")==="api") exit(0); exit(1);' "$(cfg_get ROUTERS)" 2>/dev/null; then
    f="$(cfg_get MT_CA_FILE)"; [[ -s "$f" ]] && ok "MT_CA_FILE: $f" || bad "MT_CA_FILE: файл '$f' не знайдено (потрібен для маршрутизаторів з протоколом API)"
  fi
fi
BASE_PATH="$(env_get "$ENV" APP_BASE_PATH 2>/dev/null || true)"; BASE_PATH="${BASE_PATH:-/k7f3x}"

# ============================================================= 8. database ==
step "8. База даних"
if [[ $CHECK_ONLY -eq 0 && $NEED_EDIT -eq 0 ]]; then
  sudo -u "$APP_USER" php "$TARGET/bin/migrate.php" >/dev/null && ok "Схему БД оновлено"
elif [[ $NEED_EDIT -eq 1 ]]; then
  warn "Міграцію пропущено — спершу виправте .env і запустіть скрипт ще раз"
fi
DB="$(env_get "$ENV" DB_PATH 2>/dev/null || true)"
[[ -n "$DB" && -f "$DB" ]] && ok "БД: $DB" || warn "БД ще не створено"

# ------------------------------------------------- admin panel account + backup key
NEW_ADMIN_PASS=""; NEW_BACKUP_PASS=""
if [[ $CHECK_ONLY -eq 0 && $NEED_EDIT -eq 0 ]]; then
  admins="$(sudo -u "$APP_USER" php "$TARGET/bin/admin.php" list 2>/dev/null || true)"
  if [[ -n "$admins" ]]; then
    ok "Адміністратори панелі: $(awk '{print $1}' <<<"$admins" | paste -sd, -)"
  elif [[ $ASSUME_YES -eq 0 && -t 0 ]]; then
    info "Створення облікового запису панелі адміністратора «$ADMIN_USER»"
    if sudo -u "$APP_USER" php "$TARGET/bin/admin.php" create "$ADMIN_USER"; then ok "Створено адміністратора $ADMIN_USER"
    else bad "Адміністратора не створено. Повторіть: sudo -u $APP_USER php $TARGET/bin/admin.php create $ADMIN_USER"; fi
  else
    line="$(sudo -u "$APP_USER" php "$TARGET/bin/admin.php" create "$ADMIN_USER" --random)"
    NEW_ADMIN_PASS="${line#*$'\t'}"
    ok "Створено адміністратора $ADMIN_USER з випадковим паролем (див. $CRED_FILE)"
  fi
  if out="$(sudo -u "$APP_USER" php "$TARGET/bin/settings.php" init-backup-passphrase 2>&1)"; then
    if [[ "$out" == generated:* ]]; then NEW_BACKUP_PASS="${out#generated:}"; ok "Згенеровано пароль шифрування резервних копій (див. $CRED_FILE)"
    else ok "Пароль шифрування резервних копій задано"; fi
  else
    warn "Пароль резервних копій: $out"
  fi
  if [[ -n "$NEW_ADMIN_PASS$NEW_BACKUP_PASS" ]]; then
    (
      umask 077
      echo "# VPN-GW $(date '+%F %T'): перенесіть у сейф паролів і ВИДАЛІТЬ файл: shred -u $CRED_FILE"
      if [[ -n "$NEW_ADMIN_PASS" ]]; then echo "Панель адміністратора: https://${DOMAIN}${BASE_PATH}/admin   логін: $ADMIN_USER   пароль: $NEW_ADMIN_PASS"; fi
      if [[ -n "$NEW_BACKUP_PASS" ]]; then echo "Пароль шифрування резервних копій: $NEW_BACKUP_PASS"; fi
    ) >> "$CRED_FILE"
    chmod 600 "$CRED_FILE"
  fi
fi

# ================================================ 9. FPM / Apache / systemd ==
step "9. PHP-FPM, віртуальний хост, таймер, ротація журналів"
POOL="/etc/php/${PHPV}/fpm/pool.d/vpngw.conf"
VHOST="/etc/apache2/sites-available/vpn-gw.conf"
if [[ $CHECK_ONLY -eq 0 ]]; then
  sed -e "s/php8\.4/php${PHPV}/g" -e "s|/etc/php/8\.4/|/etc/php/${PHPV}/|g" "$TARGET/deploy/php-fpm/vpngw.conf" > "$POOL"
  [[ -f "/etc/php/${PHPV}/fpm/pool.d/www.conf" ]] && mv "/etc/php/${PHPV}/fpm/pool.d/www.conf" "/etc/php/${PHPV}/fpm/pool.d/www.conf.disabled"
  CERT=/etc/ssl/vpn-gw/fullchain.pem; KEY=/etc/ssl/vpn-gw/privkey.pem
  sed -e "s|vpn-gw\.company\.ua|${DOMAIN}|g" -e "s|/k7f3x|${BASE_PATH}|g" -e "s/php8\.4/php${PHPV}/g" \
      "$TARGET/deploy/apache/vpn-gw.conf" > "$VHOST"
  # Certificate priority: own cert in /etc/ssl/vpn-gw/ -> Let's Encrypt (certbot) -> temporary self-signed.
  # Re-running the installer after certbot must not revert the vhost to the self-signed certificate.
  LE_DIR="/etc/letsencrypt/live/${DOMAIN}"
  if [[ -s $CERT && -s $KEY ]]; then
    ok "TLS: власний сертифікат /etc/ssl/vpn-gw/"
  elif [[ -s "$LE_DIR/fullchain.pem" && -s "$LE_DIR/privkey.pem" ]]; then
    sed -i -e "s|$CERT|$LE_DIR/fullchain.pem|" -e "s|$KEY|$LE_DIR/privkey.pem|" "$VHOST"
    ok "TLS: Let's Encrypt ($LE_DIR)"
  else
    sed -i -e "s|$CERT|/etc/ssl/certs/ssl-cert-snakeoil.pem|" -e "s|$KEY|/etc/ssl/private/ssl-cert-snakeoil.key|" "$VHOST"
    warn "TLS-сертифікат не знайдено (/etc/ssl/vpn-gw/ або Let's Encrypt) — тимчасово самопідписаний. Далі: certbot --apache -d $DOMAIN"
  fi
  a2ensite -q vpn-gw >/dev/null
  install -m 644 "$TARGET/deploy/systemd/vpn-gw-cleanup.service" "$TARGET/deploy/systemd/vpn-gw-cleanup.timer" \
    "$TARGET/deploy/systemd/vpn-gw-agent.service" "$TARGET/deploy/systemd/vpn-gw-agent.path" "$TARGET/deploy/systemd/vpn-gw-agent.timer" /etc/systemd/system/
  sed -i -e "s|/srv/vpn-gw|$TARGET|g" -e "s|/var/lib/vpn-gw/spool|$DATA_DIR/spool|g" \
    /etc/systemd/system/vpn-gw-agent.service /etc/systemd/system/vpn-gw-agent.path /etc/systemd/system/vpn-gw-cleanup.service
  # first install only: afterwards the agent maintains this file (admin panel -> Журнали)
  [[ -f /etc/logrotate.d/vpn-gw ]] || install -m 644 "$TARGET/deploy/logrotate/vpn-gw" /etc/logrotate.d/vpn-gw
  printf '%s\n' "# Written by install.sh; read by the VPN-GW agent (backups, web updates)" \
    "DOMAIN=$DOMAIN" "TARGET=$TARGET" "DATA_DIR=$DATA_DIR" "AGENT_DIR=$AGENT_DIR" "BACKUP_DIR=$BACKUP_DIR" \
    "APP_USER=$APP_USER" "PHPV=$PHPV" > "$CONF_DIR/install.conf"
  chmod 644 "$CONF_DIR/install.conf"
  systemctl daemon-reload
  systemctl enable --now -q vpn-gw-cleanup.timer vpn-gw-agent.timer vpn-gw-agent.path
  "php-fpm${PHPV}" -t >/dev/null 2>&1 && ok "PHP-FPM: конфігурація коректна" || bad "PHP-FPM: помилка в $POOL (php-fpm${PHPV} -t)"
  systemctl restart "php${PHPV}-fpm"
  apache2ctl configtest >/dev/null 2>&1 && ok "Apache: конфігурація коректна" || bad "Apache: помилка (apache2ctl configtest)"
  systemctl reload-or-restart apache2
  if [[ $WITH_NFT -eq 1 ]]; then
    NFT_SRC="$SRC_DIR/deploy/nftables/nftables.conf"   # edit this copy: $TARGET is overwritten on every run
    nft -c -f "$NFT_SRC" && install -m 644 "$NFT_SRC" /etc/nftables.conf \
      && systemctl enable --now -q nftables && ok "nftables увімкнено" || bad "nftables: помилка в конфігурації"
  fi
fi
[[ -f $POOL ]] && ok "Пул FPM: $POOL" || warn "Пул FPM не встановлено"
[[ -S "/run/php/php${PHPV}-fpm-vpngw.sock" ]] && ok "Сокет FPM активний" || warn "Сокет /run/php/php${PHPV}-fpm-vpngw.sock відсутній"
[[ -f $VHOST ]] && ok "Віртуальний хост: $VHOST" || warn "Віртуальний хост не встановлено"
systemctl is-active -q apache2 2>/dev/null && ok "apache2 працює" || warn "apache2 не запущено"
systemctl is-active -q "php${PHPV}-fpm" 2>/dev/null && ok "php${PHPV}-fpm працює" || warn "php${PHPV}-fpm не запущено"
systemctl is-enabled -q vpn-gw-cleanup.timer 2>/dev/null && ok "Таймер очищення увімкнено" || warn "Таймер vpn-gw-cleanup не увімкнено"
systemctl is-active -q vpn-gw-agent.path 2>/dev/null && ok "Агент (резервні копії, оновлення) очікує завдань" || warn "vpn-gw-agent.path не активний"
[[ -f "$CONF_DIR/install.conf" ]] && ok "$CONF_DIR/install.conf" || warn "$CONF_DIR/install.conf ще не створено"
[[ $WITH_NFT -eq 0 ]] && info "nftables не змінювався (відредагуйте deploy/nftables/nftables.conf і запустіть з --with-nftables)"

# ======================================================== 10. connectivity ==
step "10. Зв'язок з інфраструктурою"
tls_check() { # host port cafile [starttls]
  local out
  out="$(timeout 8 openssl s_client -connect "$1:$2" -servername "$1" -CAfile "$3" ${4:+-starttls $4} </dev/null 2>/dev/null || true)"
  grep -q "Verify return code: 0 (ok)" <<<"$out"
}
if [[ -f "$ENV" ]]; then
  LCA="$(cfg_get LDAP_CA_FILE)"; MCA="$(cfg_get MT_CA_FILE)"; LDAP_MODE="$(cfg_get LDAP_SECURITY)"; LDAP_MODE="${LDAP_MODE:-ldaps}"
  IFS=',' read -ra HOSTS <<<"$(cfg_get LDAP_HOSTS)"
  for u in "${HOSTS[@]}"; do
    u="${u// /}"; hp="${u#*://}"; h="${hp%%:*}"; p="${hp##*:}"
    [[ "$p" == "$h" ]] && { [[ "$LDAP_MODE" == ldaps* ]] && p=636 || p=389; }
    tcp_ok() { timeout 5 bash -c ">/dev/tcp/$h/$p" 2>/dev/null; }
    case "$LDAP_MODE" in
      ldaps)
        if tls_check "$h" "$p" "$LCA"; then ok "LDAPS $h:$p — TLS і сертифікат OK"
        elif tcp_ok; then bad "LDAPS $h:$p доступний, але сертифікат не довірений (LDAP_CA_FILE)"
        else bad "LDAPS $h:$p недоступний"; fi ;;
      starttls)
        if tls_check "$h" "$p" "$LCA" ldap; then ok "LDAP+StartTLS $h:$p — TLS і сертифікат OK"
        elif tcp_ok; then bad "LDAP $h:$p доступний, але StartTLS не вдався або сертифікат не довірений"
        else bad "LDAP $h:$p недоступний"; fi ;;
      ldaps-insecure|starttls-insecure)
        st=""; [[ "$LDAP_MODE" == starttls* ]] && st=ldap
        if timeout 8 openssl s_client -connect "$h:$p" ${st:+-starttls $st} </dev/null 2>/dev/null | grep -q "BEGIN CERTIFICATE"; then
          ok "LDAP $h:$p — TLS встановлено (сертифікат не перевіряється)"
        elif tcp_ok; then bad "LDAP $h:$p доступний, але TLS не встановлено"
        else bad "LDAP $h:$p недоступний"; fi ;;
      plain)
        tcp_ok && ok "LDAP $h:$p доступний (без шифрування)" || bad "LDAP $h:$p недоступний" ;;
    esac
  done
  ROUTERS_JSON="$(cfg_get ROUTERS)"; HOSTKEYS_JSON="$(cfg_get MT_SSH_HOSTKEYS)"
  while IFS=$'\t' read -r rname rhost rport rver rtr; do
    [[ -z "$rname" ]] && continue
    if [[ "$rtr" == ssh ]]; then
      # all host keys the router offers (RSA, ed25519...): the pinned one must be among them
      fp="$(timeout 8 ssh-keyscan -T 5 -p "$rport" "$rhost" 2>/dev/null | ssh-keygen -lf - 2>/dev/null | awk '{print $2}' | paste -sd' ' - || true)"
      pinned="$(php -r '$k=json_decode($argv[1],true); echo is_array($k) ? ($k[$argv[2]] ?? "") : "";' "${HOSTKEYS_JSON:-{\}}" "$rname" 2>/dev/null || true)"
      if [[ -z "$fp" ]]; then bad "MikroTik $rname $rhost:$rport (SSH) недоступний (/ip service ssh, address=, firewall)"
      elif [[ -z "$pinned" ]]; then warn "MikroTik $rname (SSH $rhost:$rport) — ключ $fp ще не підтверджено: панель → MikroTik → «Перевірити MikroTik»"
      elif [[ " $fp " == *" $pinned "* ]]; then ok "MikroTik $rname (SSH $rhost:$rport, v$rver) — ключ хоста збігається"
      else bad "MikroTik $rname (SSH) — КЛЮЧ ХОСТА ЗМІНИВСЯ: збережено $pinned, отримано $fp"; fi
      continue
    fi
    if tls_check "$rhost" "$rport" "$MCA"; then ok "MikroTik $rname ($rhost:$rport, v$rver) — TLS і сертифікат OK"
    elif timeout 5 bash -c ">/dev/tcp/$rhost/$rport" 2>/dev/null; then bad "MikroTik $rname $rhost:$rport доступний, але сертифікат не довірений (MT_CA_FILE / SAN = IP)"
    else bad "MikroTik $rname $rhost:$rport недоступний (сервіс, address= у /ip service, firewall)"; fi
  done < <(php -r '$r=json_decode($argv[1],true); if(is_array($r)) foreach($r as $x) echo ($x["name"]??""),"\t",($x["host"]??""),"\t",($x["port"]??""),"\t",($x["version"]??""),"\t",($x["transport"]??"api"),"\n";' "$ROUTERS_JSON" 2>/dev/null)
  SMTP_HOST="$(cfg_get SMTP_HOST)"
  if [[ -n "$SMTP_HOST" ]]; then
    SPORT="$(cfg_get SMTP_PORT)"; SCA="$(cfg_get SMTP_CA_FILE)"; SCA="${SCA:-/etc/ssl/certs/ca-certificates.crt}"
    tls_check "$SMTP_HOST" "${SPORT:-587}" "$SCA" smtp && ok "SMTP $SMTP_HOST — STARTTLS OK" || warn "SMTP $SMTP_HOST:${SPORT:-587} — немає з'єднання або сертифікат не довірений (листи не надсилатимуться)"
  else
    info "SMTP вимкнено (SMTP_HOST порожній)"
  fi
fi
if [[ -n "$DOMAIN" ]]; then
  addr="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR==1{print $1}' || true)"
  [[ -n "$addr" ]] && ok "DNS: $DOMAIN -> $addr" || warn "DNS: $DOMAIN не резолвиться"
  getent ahostsv6 "$DOMAIN" 2>/dev/null | grep -qv '::ffff:' && warn "DNS: для $DOMAIN є AAAA-запис — портал має бути лише IPv4" || true
fi

# ========================================================= 11. smoke test ==
step "11. Перевірка порталу"
if systemctl is-active -q apache2 2>/dev/null && [[ -n "$DOMAIN" ]]; then
  code() { curl -sk -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN$1" || echo 000; }
  [[ "$(code /)" == 404 ]] && ok "/ -> 404 (маскування)" || bad "/ має повертати 404"
  [[ "$(code /index.php)" == 404 ]] && ok "/index.php -> 404" || bad "/index.php має повертати 404"
  for css in bootstrap.min.css app.css; do
    [[ "$(code "$BASE_PATH/assets/$css")" == 200 ]] && ok "$BASE_PATH/assets/$css -> 200" || bad "Стилі порталу недоступні: $css (права на $TARGET/public)"
  done
  c="$(code "$BASE_PATH/")"
  if [[ "$c" == 200 ]]; then ok "$BASE_PATH/ -> 200"; elif [[ $NEED_EDIT -eq 1 ]]; then warn "$BASE_PATH/ -> $c (очікувано, доки не заповнено .env)"; else bad "$BASE_PATH/ -> $c (див. /var/log/apache2/vpn-gw-error.log, $DATA_DIR/logs/php-error.log)"; fi
  hdr="$(curl -skI --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" 2>/dev/null)"
  grep -qi '^server: apache$' <<<"${hdr//$'\r'/}" && ok "Версія Apache прихована" || warn "Заголовок Server розкриває версію"
else
  info "пропущено (Apache не працює або не вказано --domain)"
fi

# ============================================================== summary ==
step "Підсумок (VPN-GW $APP_VERSION)"
printf '  %sOK: %d%s   %sWARN: %d%s   %sFAIL: %d%s\n' "$G" "$PASS" "$N" "$Y" "$WARN" "$N" "$R" "$FAIL" "$N"
if [[ $NEED_EDIT -eq 1 ]]; then
  printf '\n  Далі: виправте %s (sudo -u %s nano %s) і запустіть скрипт ще раз.\n' "$ENV" "$APP_USER" "$ENV"
fi
if [[ $CHECK_ONLY -eq 0 && $NEED_EDIT -eq 0 && -n "$DOMAIN" ]]; then
  printf '\n  VPN-GW %s: портал  https://%s%s/\n' "$APP_VERSION" "$DOMAIN" "$BASE_PATH"
  printf '             панель  https://%s%s/admin   (лише з внутрішньої мережі)\n' "$DOMAIN" "$BASE_PATH"
  if [[ -n "$NEW_ADMIN_PASS$NEW_BACKUP_PASS" ]]; then
    printf '  %sНові паролі збережено в %s: перенесіть у сейф паролів і видаліть файл.%s\n' "$B" "$CRED_FILE" "$N"
  fi
  printf '%s\n' "  Наступні кроки:" \
    "   1. TLS: certbot --apache -d ${DOMAIN}  (або покладіть сертифікат у /etc/ssl/vpn-gw/)" \
    "   2. Панель -> MikroTik, Active Directory, Пошта: заповніть і натисніть «Перевірити»" \
    "   3. MikroTik (Safe Mode): deploy/mikrotik/routeros6.rsc / routeros7.rsc (drop-правила вимкнені)" \
    "   4. Пілот, потім увімкнення drop-правил (INSTALL_GUIDE, етапи 10–11)"
  if [[ $PLACEHOLDERS -eq 1 ]]; then info "Деякі параметри ще містять приклади (company.local): заповніть їх у панелі."; fi
fi
exit $(( FAIL > 0 ? 1 : 0 ))
