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
#   --yes                do not ask for confirmation
#   --version            print the version being installed
#   -h, --help
#
# Safe to run repeatedly: existing .env, database and certificates are kept.
# The script never enables MikroTik drop rules and never touches the routers.
# =============================================================================
set -Eeuo pipefail

# ---------------------------------------------------------------- settings ---
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_VERSION="$(tr -d '[:space:]' < "$SRC_DIR/VERSION" 2>/dev/null || echo unknown)"
TARGET="/srv/vpn-gw"
DATA_DIR="/var/lib/vpn-gw"
APP_USER="vpngw"
DOMAIN=""
CHECK_ONLY=0
WITH_NFT=0
ASSUME_YES=0
PHP_MIN="8.2"
PHP_EXTS=(ldap curl sodium pdo_sqlite mbstring openssl sockets json ctype tokenizer xml dom)

# ------------------------------------------------------------------ output ---
if [[ -t 1 ]]; then R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[1m'; N=$'\e[0m'; else R=; G=; Y=; B=; N=; fi
PASS=0; WARN=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  %s[ OK ]%s %s\n' "$G" "$N" "$*"; }
warn() { WARN=$((WARN+1)); printf '  %s[WARN]%s %s\n' "$Y" "$N" "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  %s[FAIL]%s %s\n' "$R" "$N" "$*"; }
step() { printf '\n%s== %s%s\n' "$B" "$*" "$N"; }
info() { printf '         %s\n' "$*"; }
die()  { printf '%s[FATAL]%s %s\n' "$R" "$N" "$*" >&2; exit 1; }
trap 'die "line $LINENO: command failed: $BASH_COMMAND"' ERR

usage() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --check) CHECK_ONLY=1 ;;
    --domain) DOMAIN="${2:-}"; shift ;;
    --target) TARGET="${2:-}"; shift ;;
    --with-nftables) WITH_NFT=1 ;;
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
PKGS=(apache2 ssl-cert ca-certificates curl openssl unzip git sqlite3 chrony ldap-utils netcat-openbsd composer
      "php${PHPV}-fpm" "php${PHPV}-cli" "php${PHPV}-ldap" "php${PHPV}-curl" "php${PHPV}-sqlite3"
      "php${PHPV}-mbstring" "php${PHPV}-xml" "php${PHPV}-intl" "php${PHPV}-opcache")
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
if have composer; then ok "Composer $(composer --version 2>/dev/null | awk '{print $3}')"; else bad "Composer не встановлено"; fi
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
for d in "$TARGET" "$DATA_DIR" "$DATA_DIR/locks" "$DATA_DIR/logs" "$DATA_DIR/sessions"; do
  mode=700; [[ "$d" == "$TARGET" ]] && mode=750
  if [[ $CHECK_ONLY -eq 0 ]]; then install -d -o "$APP_USER" -g "$APP_USER" -m "$mode" "$d"; fi
  [[ -d "$d" ]] && ok "$d" || warn "$d ще не створено"
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
  chown -R "$APP_USER:$APP_USER" "$TARGET"
  info "composer install (це може зайняти хвилину)..."
  if sudo -u "$APP_USER" -H env COMPOSER_HOME="$DATA_DIR/.composer" composer install -d "$TARGET" \
       --no-dev --classmap-authoritative --no-interaction --no-progress -q; then
    ok "Залежності встановлено"
  else
    die "composer install завершився з помилкою (перевірте доступ до packagist.org або проксі)"
  fi
  if sudo -u "$APP_USER" -H env COMPOSER_HOME="$DATA_DIR/.composer" composer audit -d "$TARGET" --no-dev --no-interaction >/dev/null 2>&1; then
    ok "composer audit: вразливостей не знайдено"
  else
    warn "composer audit знайшов проблеми: sudo -u $APP_USER composer audit -d $TARGET"
  fi
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
  for k in MT_PASS LDAP_HOSTS LDAP_UPN_SUFFIX LDAP_BASE_DN LDAP_VPN_GROUP_DN; do
    v="$(env_get "$ENV" "$k")"
    if [[ -z "$v" || "$v" == *company.local* || "$v" == *DC=company,DC=local* ]]; then warn "$k: заповніть реальним значенням"; NEED_EDIT=1; fi
  done
  for k in LDAP_CA_FILE MT_CA_FILE; do
    f="$(env_get "$ENV" "$k")"
    [[ -s "$f" ]] && ok "$k: $f" || { bad "$k: файл '$f' не знайдено (сертифікат корпоративного CA)"; }
  done
  if [[ -f "$TARGET/vendor/autoload.php" ]]; then
    as_app=(); id "$APP_USER" >/dev/null 2>&1 && as_app=(sudo -u "$APP_USER")
    if out="$(cd "$TARGET" && "${as_app[@]}" php -r 'require "vendor/autoload.php"; VpnGw\Config::load(getcwd()); echo "ok";' 2>&1)" && [[ "$out" == ok ]]; then
      ok "Config::load: налаштування коректні"
    else
      bad "Помилка конфігурації: ${out##*: }"; NEED_EDIT=1
    fi
  fi
fi
BASE_PATH="$(env_get "$ENV" APP_BASE_PATH 2>/dev/null || true)"; BASE_PATH="${BASE_PATH:-/k7f3x}"

# ============================================================= 8. database ==
step "8. База даних"
if [[ $CHECK_ONLY -eq 0 && $NEED_EDIT -eq 0 ]]; then
  sudo -u "$APP_USER" php "$TARGET/bin/migrate.php" >/dev/null && ok "Схему БД оновлено"
elif [[ $NEED_EDIT -eq 1 ]]; then
  warn "Міграцію пропущено — спершу заповніть .env і запустіть скрипт ще раз"
fi
DB="$(env_get "$ENV" DB_PATH 2>/dev/null || true)"
[[ -n "$DB" && -f "$DB" ]] && ok "БД: $DB" || warn "БД ще не створено"

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
  if [[ ! -s $CERT || ! -s $KEY ]]; then
    sed -i -e "s|$CERT|/etc/ssl/certs/ssl-cert-snakeoil.pem|" -e "s|$KEY|/etc/ssl/private/ssl-cert-snakeoil.key|" "$VHOST"
    warn "TLS-сертифікат не знайдено в /etc/ssl/vpn-gw/ — тимчасово самопідписаний. Далі: certbot --apache -d $DOMAIN"
  fi
  a2ensite -q vpn-gw >/dev/null
  install -m 644 "$TARGET/deploy/systemd/vpn-gw-cleanup.service" "$TARGET/deploy/systemd/vpn-gw-cleanup.timer" /etc/systemd/system/
  install -m 644 "$TARGET/deploy/logrotate/vpn-gw" /etc/logrotate.d/vpn-gw
  systemctl daemon-reload
  systemctl enable --now -q vpn-gw-cleanup.timer
  "php-fpm${PHPV}" -t >/dev/null 2>&1 && ok "PHP-FPM: конфігурація коректна" || bad "PHP-FPM: помилка в $POOL (php-fpm${PHPV} -t)"
  systemctl restart "php${PHPV}-fpm"
  apache2ctl configtest >/dev/null 2>&1 && ok "Apache: конфігурація коректна" || bad "Apache: помилка (apache2ctl configtest)"
  systemctl reload-or-restart apache2
  if [[ $WITH_NFT -eq 1 ]]; then
    nft -c -f "$TARGET/deploy/nftables/nftables.conf" && install -m 644 "$TARGET/deploy/nftables/nftables.conf" /etc/nftables.conf \
      && systemctl enable --now -q nftables && ok "nftables увімкнено" || bad "nftables: помилка в конфігурації"
  fi
fi
[[ -f $POOL ]] && ok "Пул FPM: $POOL" || warn "Пул FPM не встановлено"
[[ -S "/run/php/php${PHPV}-fpm-vpngw.sock" ]] && ok "Сокет FPM активний" || warn "Сокет /run/php/php${PHPV}-fpm-vpngw.sock відсутній"
[[ -f $VHOST ]] && ok "Віртуальний хост: $VHOST" || warn "Віртуальний хост не встановлено"
systemctl is-active -q apache2 2>/dev/null && ok "apache2 працює" || warn "apache2 не запущено"
systemctl is-active -q "php${PHPV}-fpm" 2>/dev/null && ok "php${PHPV}-fpm працює" || warn "php${PHPV}-fpm не запущено"
systemctl is-enabled -q vpn-gw-cleanup.timer 2>/dev/null && ok "Таймер очищення увімкнено" || warn "Таймер vpn-gw-cleanup не увімкнено"
[[ $WITH_NFT -eq 0 ]] && info "nftables не змінювався (відредагуйте deploy/nftables/nftables.conf і запустіть з --with-nftables)"

# ======================================================== 10. connectivity ==
step "10. Зв'язок з інфраструктурою"
tls_check() { # host port cafile [starttls]
  local out
  out="$(timeout 8 openssl s_client -connect "$1:$2" -servername "$1" -CAfile "$3" ${4:+-starttls $4} </dev/null 2>/dev/null || true)"
  grep -q "Verify return code: 0 (ok)" <<<"$out"
}
if [[ -f "$ENV" ]]; then
  LCA="$(env_get "$ENV" LDAP_CA_FILE)"; MCA="$(env_get "$ENV" MT_CA_FILE)"
  IFS=',' read -ra HOSTS <<<"$(env_get "$ENV" LDAP_HOSTS)"
  for u in "${HOSTS[@]}"; do
    u="${u// /}"; hp="${u#ldaps://}"; h="${hp%%:*}"; p="${hp##*:}"; [[ "$p" == "$h" ]] && p=636
    if tls_check "$h" "$p" "$LCA"; then ok "LDAPS $h:$p — TLS і сертифікат OK"
    elif timeout 5 bash -c ">/dev/tcp/$h/$p" 2>/dev/null; then bad "LDAPS $h:$p доступний, але сертифікат не довірений (LDAP_CA_FILE)"
    else bad "LDAPS $h:$p недоступний"; fi
  done
  ROUTERS_JSON="$(env_get "$ENV" ROUTERS)"
  while IFS=$'\t' read -r rname rhost rport rver; do
    [[ -z "$rname" ]] && continue
    if tls_check "$rhost" "$rport" "$MCA"; then ok "MikroTik $rname ($rhost:$rport, v$rver) — TLS і сертифікат OK"
    elif timeout 5 bash -c ">/dev/tcp/$rhost/$rport" 2>/dev/null; then bad "MikroTik $rname $rhost:$rport доступний, але сертифікат не довірений (MT_CA_FILE / SAN = IP)"
    else bad "MikroTik $rname $rhost:$rport недоступний (сервіс, address= у /ip service, firewall)"; fi
  done < <(php -r '$r=json_decode($argv[1],true); if(is_array($r)) foreach($r as $x) echo ($x["name"]??""),"\t",($x["host"]??""),"\t",($x["port"]??""),"\t",($x["version"]??""),"\n";' "$ROUTERS_JSON" 2>/dev/null)
  SMTP_HOST="$(env_get "$ENV" SMTP_HOST)"
  if [[ -n "$SMTP_HOST" ]]; then
    SPORT="$(env_get "$ENV" SMTP_PORT)"; SCA="$(env_get "$ENV" SMTP_CA_FILE)"; SCA="${SCA:-/etc/ssl/certs/ca-certificates.crt}"
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
  printf '\n  Далі: заповніть %s (sudo -u %s nano %s) і запустіть скрипт ще раз.\n' "$ENV" "$APP_USER" "$ENV"
fi
if [[ $FAIL -eq 0 && $NEED_EDIT -eq 0 && $CHECK_ONLY -eq 0 ]]; then
  cat <<EOF

  VPN-GW ${APP_VERSION} встановлено.
  Портал: https://${DOMAIN}${BASE_PATH}/
  Наступні кроки:
   1. TLS: certbot --apache -d ${DOMAIN}  (або покладіть сертифікат у /etc/ssl/vpn-gw/)
   2. MikroTik (Safe Mode): deploy/mikrotik/routeros6.rsc / routeros7.rsc — drop-правила лишаються вимкненими
   3. Перевірка: sudo -u ${APP_USER} php ${TARGET}/bin/mt-check.php grant <тестова публічна IP>
   4. Пілот, потім увімкнення drop-правил (INSTALL_GUIDE, етапи 10–11)
EOF
fi
exit $(( FAIL > 0 ? 1 : 0 ))
