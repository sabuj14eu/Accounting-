#!/usr/bin/env bash
#
# Prepare the Contabo server for Shop Profit Intelligence — INFRASTRUCTURE ONLY.
#
#   sudo bash prepare-server.sh
#
# What it creates, each one separate from the accounting application and from
# the trading platform:
#   - a system user and a root directory       (/srv/shop-intelligence)
#   - a database and a database user            (shop_intelligence, own grants)
#   - a storage root                            (/var/lib/shop-intelligence)
#   - a PHP-FPM pool                            (php8.5-fpm-shop.sock)
#   - an nginx server block and a TLS cert      (shop.signalmesh.dev)
#   - a backup directory                        (/var/backups/shop-intelligence)
#
# What it does NOT do: deploy an application. There is none yet — no UI, no
# migrations, no login (docs/SPEC_AUDIT_2026-09-08.md §4). The document root
# gets ONE static page that says so, in plain words, so that a browser hitting
# the domain sees the truth rather than a default nginx page or a 200 that
# implies something is running.
#
# Safe to re-run: every step is idempotent. Touches nothing under /srv/accounting
# and nothing belonging to the trading platform.

set -euo pipefail

DOMAIN="${DOMAIN:-shop.signalmesh.dev}"
REPO="${REPO:-git@github.com:sabuj14eu/Accounting-.git}"   # used only when not run from a checkout
BRANCH="${BRANCH:-claude/regression-map-audit-lis0w8}"
APP_USER="${APP_USER:-shop}"
APP_ROOT="${APP_ROOT:-/srv/shop-intelligence}"
STORAGE_ROOT="${STORAGE_ROOT:-/var/lib/shop-intelligence}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/shop-intelligence}"
DB_NAME="${DB_NAME:-shop_intelligence}"
DB_USER="${DB_USER:-shop_intelligence}"
ISSUE_TLS="${ISSUE_TLS:-yes}"
PHP_VER="${PHP_VER:-8.5}"
PHP_FPM_POOL="shop"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    UWAGA: %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mBŁĄD: %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Uruchom przez sudo/root."

case "$DB_NAME$DB_USER$APP_ROOT$STORAGE_ROOT" in
    *accounting*|*liberu*) die "Nazwa wskazuje na aplikację księgową. Shop Intelligence ma własną bazę, własnego użytkownika i własny katalog." ;;
esac

# ---------------------------------------------------------------------------
step "1/8  Pakiety"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg lsb-release software-properties-common \
    git nginx mariadb-server >/dev/null

# PHP comes from the same third-party repository the accounting installer uses
# (Ubuntu: ppa:ondrej/php, Debian: packages.sury.org). Ubuntu's own archive
# ships one PHP version per release and it is rarely the one wanted. The
# analysis core itself needs only 8.2; 8.5 is chosen so that this box can
# later also host the accounting application, which requires 8.5, without a
# second PHP install.
if ! command -v "php$PHP_VER" >/dev/null 2>&1; then
    if grep -qi ubuntu /etc/os-release; then
        add-apt-repository -y ppa:ondrej/php >/dev/null
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -q
fi
apt-get install -y -q "php$PHP_VER-fpm" "php$PHP_VER-cli" "php$PHP_VER-mysql" "php$PHP_VER-mbstring" \
    "php$PHP_VER-xml" "php$PHP_VER-intl" "php$PHP_VER-bcmath" >/dev/null \
    || die "Nie udało się zainstalować PHP $PHP_VER. Sprawdź, czy repozytorium ondrej/sury zostało dodane (apt-get update powyżej)."
info "nginx, MariaDB, PHP $("php$PHP_VER" -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
step "2/8  Użytkownik systemowy i katalogi (własne)"
# ---------------------------------------------------------------------------
id -u "$APP_USER" >/dev/null 2>&1 || useradd -r -m -d "$APP_ROOT" -s /bin/bash "$APP_USER"
mkdir -p "$APP_ROOT/public" "$STORAGE_ROOT" "$BACKUP_DIR"
chown -R "$APP_USER:$APP_USER" "$APP_ROOT" "$STORAGE_ROOT"
# useradd -m creates the home with mode 750 on current Ubuntu, and nginx runs
# as www-data: it then cannot enter public/, try_files falls through to
# index.php, and PHP-FPM answers 404 "File not found" for a page that exists.
# The root and public/ must be traversable; the storage root must not be.
chmod 755 "$APP_ROOT" "$APP_ROOT/public"
chmod 750 "$STORAGE_ROOT"
chown root:root "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
info "$APP_USER : $APP_ROOT  storage: $STORAGE_ROOT  backup: $BACKUP_DIR"

# ---------------------------------------------------------------------------
step "3/8  Kod (analysis core — do testów i demo, nie serwowany)"
# ---------------------------------------------------------------------------
# The repository is PRIVATE, so an HTTPS clone from GitHub asks for a token
# and password prompts fail. This script therefore never needs GitHub: it
# copies the checkout it is running FROM (the one you already put on the box)
# with a local git clone. Only when there is no checkout around it does it
# fall back to $REPO, which then must be an SSH URL with a deploy key.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_SOURCE="$(cd "$SCRIPT_DIR/../.." && pwd)"
if [ -d "$LOCAL_SOURCE/.git" ]; then
    SOURCE="$LOCAL_SOURCE"
    BRANCH="$(git -C "$LOCAL_SOURCE" rev-parse --abbrev-ref HEAD)"
    info "źródło: lokalna kopia $LOCAL_SOURCE (gałąź $BRANCH) — bez połączenia z GitHub"
else
    SOURCE="$REPO"
    info "źródło: $REPO (gałąź $BRANCH) — wymaga klucza deploy, repozytorium jest prywatne"
fi

# Cloned as root, not as $APP_USER: the source checkout usually lives under
# /root, which the application user cannot enter, so a clone run as that user
# reports "repository does not exist". Ownership is handed over afterwards.
GIT="git -c safe.directory=$APP_ROOT/app"
if [ -d "$APP_ROOT/app/.git" ]; then
    $GIT -C "$APP_ROOT/app" remote set-url origin "$SOURCE"
    $GIT -C "$APP_ROOT/app" fetch origin "$BRANCH"
    $GIT -C "$APP_ROOT/app" checkout -B "$BRANCH" "origin/$BRANCH"
else
    git clone --branch "$BRANCH" "$SOURCE" "$APP_ROOT/app"
fi
chown -R "$APP_USER:$APP_USER" "$APP_ROOT/app"
info "$($GIT -C "$APP_ROOT/app" log --oneline -1)"
( cd "$APP_ROOT/app/shop-intelligence" && sudo -u "$APP_USER" bash bin/check-shop-isolation.sh >/dev/null ) \
    || die "Strażnik izolacji nie przeszedł — nie przygotowuję serwera."
info "izolacja Shop Intelligence: potwierdzona mechanicznie"

# ---------------------------------------------------------------------------
step "4/8  Baza danych (własna: $DB_NAME / $DB_USER)"
# ---------------------------------------------------------------------------
systemctl enable --now mariadb >/dev/null 2>&1 || true
DB_PASS_FILE="$APP_ROOT/.db-password"
if [ ! -f "$DB_PASS_FILE" ]; then
    head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 28 > "$DB_PASS_FILE"
    chown "$APP_USER:$APP_USER" "$DB_PASS_FILE"; chmod 600 "$DB_PASS_FILE"
fi
DB_PASS="$(cat "$DB_PASS_FILE")"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\_drill\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
# Prove the grant is scoped: this user must NOT be able to see any other schema.
VISIBLE="$(mysql -N -B -u "$DB_USER" -p"$DB_PASS" -h 127.0.0.1 -e "SHOW DATABASES;" | grep -Ev "^(information_schema|$DB_NAME|${DB_NAME}_drill)$" || true)"
[ -z "$VISIBLE" ] || die "Użytkownik $DB_USER widzi inne bazy: $VISIBLE — uprawnienia są za szerokie."
info "baza $DB_NAME, użytkownik $DB_USER — widzi wyłącznie własną bazę (sprawdzone)"

# ---------------------------------------------------------------------------
step "5/8  .env (własny)"
# ---------------------------------------------------------------------------
ENV_FILE="$APP_ROOT/.env"
if [ ! -f "$ENV_FILE" ]; then
    sudo -u "$APP_USER" cp "$APP_ROOT/app/shop-intelligence/.env.example" "$ENV_FILE"
fi
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else echo "${key}=${value}" >> "$ENV_FILE"; fi
}
set_env APP_URL "https://$DOMAIN"
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env SESSION_DOMAIN "$DOMAIN"
set_env SESSION_COOKIE shop_intelligence_session
set_env SHOP_STORAGE_ROOT "$STORAGE_ROOT"
chown "$APP_USER:$APP_USER" "$ENV_FILE"; chmod 600 "$ENV_FILE"
info "$ENV_FILE (chmod 600; hasło bazy tylko tutaj i w $DB_PASS_FILE)"

# ---------------------------------------------------------------------------
step "6/8  PHP-FPM — własna pula ($PHP_FPM_POOL)"
# ---------------------------------------------------------------------------
cat > "/etc/php/$PHP_VER/fpm/pool.d/$PHP_FPM_POOL.conf" <<POOL
; Shop Profit Intelligence — its own pool. Never shared with accounting or trading.
[$PHP_FPM_POOL]
user = $APP_USER
group = $APP_USER
listen = /run/php/php$PHP_VER-fpm-$PHP_FPM_POOL.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
php_admin_value[open_basedir] = $APP_ROOT:$STORAGE_ROOT:/tmp
POOL
systemctl enable --now "php$PHP_VER-fpm" >/dev/null 2>&1 || true
systemctl restart "php$PHP_VER-fpm"
info "php$PHP_VER-fpm-$PHP_FPM_POOL.sock (open_basedir ogranicza do $APP_ROOT i $STORAGE_ROOT)"

# ---------------------------------------------------------------------------
step "7/8  Strona-prawda i nginx"
# ---------------------------------------------------------------------------
cat > "$APP_ROOT/public/index.html" <<HTML
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><title>Shop Intelligence — nie wdrożono</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#222}
code{background:#eee;padding:.1em .3em}</style></head>
<body>
<h1>Shop Profit Intelligence</h1>
<p><strong>Infrastruktura przygotowana. Aplikacja nie jest wdrożona.</strong></p>
<p>Ten adres ma własną bazę danych, własnego użytkownika, własną pulę PHP i własny
certyfikat — i na razie nic więcej. Nie ma interfejsu, nie ma logowania, nie ma
importów, nie ma zamknięcia miesiąca. Rdzeń analityczny istnieje wyłącznie jako
biblioteka z testami.</p>
<p>To nie jest system księgowy. Oficjalne rozliczenia: <a href="https://account.signalmesh.dev">account.signalmesh.dev</a>.</p>
<p><small>Stan opisany w <code>shop-intelligence/docs/SPEC_AUDIT_2026-09-08.md</code>.</small></p>
</body></html>
HTML
chown "$APP_USER:$APP_USER" "$APP_ROOT/public/index.html"

# HTTP-only block first; certbot upgrades it to the TLS block once DNS resolves.
cat > "/etc/nginx/sites-available/$DOMAIN" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_ROOT/public;
    index index.php index.html;
    charset utf-8;
    client_max_body_size 32M;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    access_log /var/log/nginx/shop-intelligence.access.log;
    error_log  /var/log/nginx/shop-intelligence.error.log;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass unix:/run/php/php$PHP_VER-fpm-$PHP_FPM_POOL.sock;
        fastcgi_read_timeout 120;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/$DOMAIN"
nginx -t >/dev/null || die "Konfiguracja nginx nie przechodzi testu."
systemctl reload nginx
info "vhost $DOMAIN — oddzielny server block; account.signalmesh.dev nietknięty"

# ---------------------------------------------------------------------------
step "8/8  HTTPS"
# ---------------------------------------------------------------------------
if [ "$ISSUE_TLS" = "yes" ]; then
    apt-get install -y -q certbot python3-certbot-nginx >/dev/null
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
            --register-unsafely-without-email --redirect >/dev/null 2>&1; then
        info "certyfikat wystawiony, przekierowanie na HTTPS włączone"
    else
        warn "Certbot nie wystawił certyfikatu. Najczęstsza przyczyna: rekord A dla $DOMAIN"
        warn "nie wskazuje jeszcze na ten serwer, albo w Cloudflare jest włączone proxy (pomarańczowa chmurka)"
        warn "przed wystawieniem certyfikatu. Ustaw rekord jako 'DNS only', odczekaj, uruchom:"
        warn "  certbot --nginx -d $DOMAIN --redirect"
    fi
fi

cat <<SUMMARY

################################################################################
  PRZYGOTOWANO INFRASTRUKTURĘ — APLIKACJA NIE JEST WDROŻONA
################################################################################

  ADRES         https://$DOMAIN   (jedna strona: "nie wdrożono")
  BAZA          $DB_NAME / $DB_USER  (widzi tylko własną bazę — sprawdzone)
  STORAGE       $STORAGE_ROOT
  PHP-FPM       php$PHP_VER-fpm-$PHP_FPM_POOL.sock
  KOD           $APP_ROOT/app  (gałąź $BRANCH)
  BACKUP        $BACKUP_DIR  (skrypt kopii powstanie razem z migracjami)

  ODDZIELENIE
    nic w /srv/accounting nie zostało dotknięte
    nic z systemu tradingowego nie zostało dotknięte ani użyte

  CO DALEJ (w tej kolejności, z docs/FABLE_BRIEFING_2026-09-08.md §6)
    P0  schemat + migracje pod $DB_NAME · własne logowanie · niezmienne zamknięcie miesiąca
    P1  trzy strony wg shop-intelligence/docs/BANKING_UX.md
    P2  importy (bank, terminal, Glovo, Uber Eats) — każdy fail-closed

  SPRAWDZENIE
    cd $APP_ROOT/app/shop-intelligence && php bin/shop-demo
    ./bin/check-shop-isolation.sh
################################################################################
SUMMARY
