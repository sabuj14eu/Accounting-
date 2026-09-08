#!/usr/bin/env bash
#
# One-shot production install of the Polish accounting application on a fresh
# Ubuntu 22.04/24.04 or Debian 12 server.
#
#   sudo bash deploy-contabo.sh
#
# Installs PHP 8.5, MariaDB, Redis, nginx and the application; creates the
# database, the system user, the systemd units and the TLS certificate; runs the
# migrations; and prints the URL and the admin credentials at the end.
#
# It touches NOTHING belonging to the trading platform. It creates its own
# database, its own database user, its own PHP-FPM pool, its own systemd units
# and its own nginx server block. If app.signalmesh.dev is already served by
# this machine, it keeps working exactly as before.
#
# Safe to re-run: every step is idempotent.

set -euo pipefail

# ---------------------------------------------------------------------------
# Settings — change these before running if you want different values.
# ---------------------------------------------------------------------------
DOMAIN="${DOMAIN:-account.signalmesh.dev}"
REPO="${REPO:-https://github.com/sabuj14eu/Accounting-}"
BRANCH="${BRANCH:-claude/ksef-2-fa3-integration-84856a}"
APP_USER="${APP_USER:-accounting}"
APP_ROOT="${APP_ROOT:-/srv/accounting}"
DB_NAME="${DB_NAME:-accounting}"
DB_USER="${DB_USER:-accounting}"
REDIS_DB="${REDIS_DB:-3}"
REDIS_CACHE_DB="${REDIS_CACHE_DB:-4}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
ISSUE_TLS="${ISSUE_TLS:-yes}"

PHP_FPM_POOL="accounting"
PHP_VER="8.5"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    UWAGA: %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mBŁĄD: %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Uruchom przez sudo/root."

# ---------------------------------------------------------------------------
step "1/12  Pakiety systemowe"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg lsb-release software-properties-common \
    git unzip nginx mariadb-server redis-server ufw >/dev/null
info "nginx, MariaDB, Redis, git zainstalowane"

# ---------------------------------------------------------------------------
step "2/12  PHP $PHP_VER"
# ---------------------------------------------------------------------------
# The ERP uses #[\Override] on properties, which is PHP 8.5 syntax: on 8.4 the
# application fails to PARSE, and the error names an attribute rather than a
# version. So 8.5 is a hard requirement, not a preference.
if ! command -v "php$PHP_VER" >/dev/null 2>&1; then
    if [ -f /etc/debian_version ] && grep -qi ubuntu /etc/os-release; then
        add-apt-repository -y ppa:ondrej/php >/dev/null
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -q
fi

apt-get install -y -q \
    "php$PHP_VER-cli" "php$PHP_VER-fpm" "php$PHP_VER-mysql" "php$PHP_VER-mbstring" \
    "php$PHP_VER-xml" "php$PHP_VER-xsl" "php$PHP_VER-curl" "php$PHP_VER-zip" \
    "php$PHP_VER-gd" "php$PHP_VER-intl" "php$PHP_VER-bcmath" "php$PHP_VER-redis" >/dev/null \
    || die "Nie udało się zainstalować PHP $PHP_VER. Sprawdź repozytorium ondrej/sury."

PHP_BIN="$(command -v "php$PHP_VER")"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' \
    || die "PHP $($PHP_BIN -r 'echo PHP_VERSION;') to za mało — Liberu ERP wymaga 8.5."
info "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')"

if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "$PHP_BIN" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null
    rm -f /tmp/composer-setup.php
fi
info "Composer $(composer --version 2>/dev/null | head -1)"

# ---------------------------------------------------------------------------
step "3/12  Użytkownik systemowy i katalogi"
# ---------------------------------------------------------------------------
id -u "$APP_USER" >/dev/null 2>&1 || useradd -r -m -d "$APP_ROOT" -s /bin/bash "$APP_USER"
mkdir -p "$APP_ROOT"
chown "$APP_USER:$APP_USER" "$APP_ROOT"
info "$APP_USER : $APP_ROOT"

# ---------------------------------------------------------------------------
step "4/12  Kod aplikacji"
# ---------------------------------------------------------------------------
if [ -d "$APP_ROOT/app/.git" ]; then
    sudo -u "$APP_USER" git -C "$APP_ROOT/app" fetch origin "$BRANCH"
    sudo -u "$APP_USER" git -C "$APP_ROOT/app" checkout -B "$BRANCH" "origin/$BRANCH"
else
    sudo -u "$APP_USER" git clone --branch "$BRANCH" "$REPO" "$APP_ROOT/app"
fi
info "$(sudo -u "$APP_USER" git -C "$APP_ROOT/app" log --oneline -1)"

# ---------------------------------------------------------------------------
step "5/12  Baza danych (własna, oddzielna od tradingu)"
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
-- A dedicated scratch namespace for the restore drill. Without it the drill
-- cannot prove a backup restores, and an unrestored backup is a belief rather
-- than a backup. Still scoped: nothing here reaches any other schema.
GRANT ALL PRIVILEGES ON \`${DB_NAME}\_drill\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
# Grants are scoped to this database and its restore-drill scratch namespace.
# The accounting user cannot read the trading schema even if a query tried.
info "baza $DB_NAME, użytkownik $DB_USER (uprawnienia wyłącznie do tej bazy)"

# ---------------------------------------------------------------------------
step "6/12  Instalacja Liberu ERP + modułu Poland (kilka minut)"
# ---------------------------------------------------------------------------
sudo -u "$APP_USER" env PATH="$PATH" COMPOSER_ALLOW_SUPERUSER=0 \
    bash "$APP_ROOT/app/bin/install-foundation.sh" "$APP_ROOT/foundation"

# ---------------------------------------------------------------------------
step "7/12  Konfiguracja .env"
# ---------------------------------------------------------------------------
ENV_FILE="$APP_ROOT/foundation/.env"
[ -f "$ENV_FILE" ] || sudo -u "$APP_USER" cp "$APP_ROOT/app/.env.example" "$ENV_FILE"

set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        echo "${key}=${value}" >> "$ENV_FILE"
    fi
}

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$DOMAIN"
set_env APP_LOCALE pl
set_env APP_TIMEZONE Europe/Warsaw
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env REDIS_DB "$REDIS_DB"
set_env REDIS_CACHE_DB "$REDIS_CACHE_DB"
set_env QUEUE_CONNECTION redis
set_env CACHE_STORE redis
set_env SESSION_DRIVER redis
# FAIL CLOSED. Production refuses to settle on rates nobody has confirmed
# against the issuing authority. This is the release gate's requirement: the
# default must be the safe one, and relaxing it must be a conscious act by an
# operator who has read why.
#
# To see orientation-only figures before verification, set this to false by
# hand — every screen then carries NOT VERIFIED and no figure may be filed.
set_env POLAND_REQUIRE_OFFICIAL_RATES true

# KSeF transport stays off until a client is implemented against the current
# official API and live-tested. Production may never select the fake transport;
# TransportGate throws rather than letting a lost connection read as
# "no invoices found".
set_env KSEF_TRANSPORT_ENABLED false
set_env KSEF_TRANSPORT disabled
# The first real KSeF environment is TEST; production is a documented gate
# (docs/KSEF_PRODUCTION_GATE.md), never an installer default.
set_env KSEF_ENVIRONMENT test
set_env KSEF_SYNC_SCHEDULE_ENABLED false
chown "$APP_USER:$APP_USER" "$ENV_FILE"; chmod 600 "$ENV_FILE"

cd "$APP_ROOT/foundation"
grep -q "^APP_KEY=base64:" "$ENV_FILE" || sudo -u "$APP_USER" "$PHP_BIN" artisan key:generate --force
info "APP_URL=https://$DOMAIN, baza i Redis ustawione"

# ---------------------------------------------------------------------------
step "8/12  Kopia zapasowa przed migracją, potem migracje"
# ---------------------------------------------------------------------------
# backup -> migrate -> restart -> verify. A migration that alters a table with
# data in it (the KSeF migration does) is preceded by a dump of the whole
# database, kept on disk, so a failed migration is a restore rather than a
# loss. On a fresh install the database is empty and the step reports that.
BACKUP_DIR="$APP_ROOT/backups"
mkdir -p "$BACKUP_DIR"; chown "$APP_USER:$APP_USER" "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
TABLE_COUNT="$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)"
if [ "${TABLE_COUNT:-0}" -gt 0 ]; then
    PRE_MIGRATE_DUMP="$BACKUP_DIR/pre-migrate-$(date +%Y%m%d-%H%M%S)-$(sudo -u "$APP_USER" git -C "$APP_ROOT/app" rev-parse --short HEAD).sql.gz"
    mysqldump --host=127.0.0.1 --user="$DB_USER" --password="$DB_PASS" \
        --single-transaction --routines --triggers "$DB_NAME" | gzip > "$PRE_MIGRATE_DUMP"
    [ -s "$PRE_MIGRATE_DUMP" ] || die "Kopia zapasowa przed migracją jest pusta — przerwano PRZED migracją."
    chown "$APP_USER:$APP_USER" "$PRE_MIGRATE_DUMP"; chmod 600 "$PRE_MIGRATE_DUMP"
    info "kopia przed migracją: $PRE_MIGRATE_DUMP ($(du -h "$PRE_MIGRATE_DUMP" | cut -f1), $TABLE_COUNT tabel)"
    ls -1t "$BACKUP_DIR"/pre-migrate-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f   # keep the last 10
else
    PRE_MIGRATE_DUMP=""
    info "baza pusta (świeża instalacja) — nie ma czego kopiować"
fi
sudo -u "$APP_USER" "$PHP_BIN" artisan migrate --force
sudo -u "$APP_USER" "$PHP_BIN" artisan storage:link >/dev/null 2>&1 || true
chown -R "$APP_USER:$APP_USER" "$APP_ROOT/foundation/storage" "$APP_ROOT/foundation/bootstrap/cache"
chmod -R ug+rwX "$APP_ROOT/foundation/storage" "$APP_ROOT/foundation/bootstrap/cache"

# ---------------------------------------------------------------------------
step "9/12  PHP-FPM (własna pula) i systemd"
# ---------------------------------------------------------------------------
POOL_FILE="/etc/php/$PHP_VER/fpm/pool.d/$PHP_FPM_POOL.conf"
cat > "$POOL_FILE" <<POOL
; Accounting application — its own pool.
; Sharing the trading platform's pool would let a slow accounting request
; starve the trading dashboard of workers.
[$PHP_FPM_POOL]
user = $APP_USER
group = $APP_USER
listen = /run/php/php$PHP_VER-fpm-$PHP_FPM_POOL.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
POOL
systemctl enable --now "php$PHP_VER-fpm" >/dev/null 2>&1 || true
systemctl restart "php$PHP_VER-fpm"

for unit in accounting-queue.service accounting-scheduler.service accounting-scheduler.timer; do
    if [ -f "$APP_ROOT/app/deploy/systemd/$unit" ]; then
        sed -e "s|/srv/accounting/foundation|$APP_ROOT/foundation|g" \
            -e "s|/usr/bin/php|$PHP_BIN|g" \
            -e "s|^User=.*|User=$APP_USER|" -e "s|^Group=.*|Group=$APP_USER|" \
            "$APP_ROOT/app/deploy/systemd/$unit" > "/etc/systemd/system/$unit"
    fi
done
systemctl daemon-reload
systemctl enable --now accounting-queue.service accounting-scheduler.timer >/dev/null 2>&1 || true
# `enable --now` leaves an already-running worker on the OLD code. An update
# without this restart would poll KSeF with last release's classes.
systemctl restart accounting-queue.service >/dev/null 2>&1 || true
info "pula php-fpm-$PHP_FPM_POOL, kolejka (zrestartowana) i scheduler uruchomione"

# ---------------------------------------------------------------------------
step "10/12  nginx"
# ---------------------------------------------------------------------------
cat > "/etc/nginx/sites-available/$DOMAIN" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_ROOT/foundation/public;
    index index.php;
    charset utf-8;
    client_max_body_size 32M;

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    access_log /var/log/nginx/accounting.access.log;
    error_log  /var/log/nginx/accounting.error.log;

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
info "vhost $DOMAIN (oddzielny server block — nic nie zmienia w tradingu)"

# ---------------------------------------------------------------------------
step "11/12  HTTPS"
# ---------------------------------------------------------------------------
if [ "$ISSUE_TLS" = "yes" ]; then
    apt-get install -y -q certbot python3-certbot-nginx >/dev/null
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
            --register-unsafely-without-email --redirect >/dev/null 2>&1; then
        info "certyfikat wystawiony, przekierowanie na HTTPS włączone"
    else
        warn "Certbot nie wystawił certyfikatu. Najczęstsza przyczyna: DNS dla"
        warn "$DOMAIN nie wskazuje jeszcze na ten serwer. Ustaw rekord A i uruchom:"
        warn "  certbot --nginx -d $DOMAIN --redirect"
        warn "Do tego czasu strona działa po HTTP."
    fi
else
    info "pominięto (ISSUE_TLS=no)"
fi

# ---------------------------------------------------------------------------
step "12/12  Konto administratora i profil podatnika"
# ---------------------------------------------------------------------------
ADMIN_PASS="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 18)"
if [ -z "$ADMIN_EMAIL" ]; then
    ADMIN_EMAIL="admin@${DOMAIN}"
fi

cd "$APP_ROOT/foundation"
CREATED="$(sudo -u "$APP_USER" "$PHP_BIN" artisan tinker --execute "
\$email = '$ADMIN_EMAIL';
\$user = App\\Models\\User::where('email', \$email)->first();
if (\$user) { echo 'existing'; }
else {
    App\\Models\\User::create([
        'name' => 'Administrator',
        'email' => \$email,
        'password' => Illuminate\\Support\\Facades\\Hash::make('$ADMIN_PASS'),
        'email_verified_at' => now(),
    ]);
    echo 'created';
}
" 2>/dev/null | tail -1)"

sudo -u "$APP_USER" "$PHP_BIN" artisan config:cache >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" artisan route:cache >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" artisan view:cache  >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" artisan queue:restart >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
step "Weryfikacja — KSeF Gate 1 (runtime) na tej instalacji"
# ---------------------------------------------------------------------------
# The last word belongs to the running application, not to the installer.
KSEF_GATE1="FAILED"
if PHP_BIN="$PHP_BIN" bash "$APP_ROOT/app/bin/ksef-runtime-check.sh" "$APP_ROOT/foundation"; then
    KSEF_GATE1="PASSED (mechanical rows; HUMAN rows remain)"
else
    warn "KSeF Gate 1 nie przeszedł — aplikacja działa, ale integracja KSeF nie jest"
    warn "zweryfikowana na tym serwerze. Zapisz wynik w docs/KSEF_PRODUCTION_GATE.md."
fi

# ---------------------------------------------------------------------------
SCHEME="https"; grep -q "listen 443" "/etc/nginx/sites-available/$DOMAIN" || SCHEME="http"

cat <<SUMMARY

################################################################################
  GOTOWE — aplikacja księgowa działa
################################################################################

  ADRES        $SCHEME://$DOMAIN
  PULPIT       $SCHEME://$DOMAIN/poland      <- "co muszę zapłacić"
  LOGOWANIE    $SCHEME://$DOMAIN/login
  REJESTRACJA  $SCHEME://$DOMAIN/register

  KONTO ADMINISTRATORA
SUMMARY
if [ "$CREATED" = "created" ]; then
cat <<SUMMARY
    e-mail   $ADMIN_EMAIL
    hasło    $ADMIN_PASS

    ZAPISZ TO HASŁO TERAZ — nie jest nigdzie przechowywane w postaci jawnej.
    Zmień je po pierwszym logowaniu.
SUMMARY
else
cat <<SUMMARY
    e-mail   $ADMIN_EMAIL  (konto już istniało — hasło bez zmian)
    Reset:   $SCHEME://$DOMAIN/forgot-password
SUMMARY
fi
cat <<SUMMARY

  ODDZIELENIE OD TRADINGU
    baza          $DB_NAME (własny użytkownik, uprawnienia tylko do niej)
    Redis         DB $REDIS_DB / cache $REDIS_CACHE_DB
    PHP-FPM       php$PHP_VER-fpm-$PHP_FPM_POOL.sock (własna pula)
    systemd       accounting-queue.service, accounting-scheduler.timer
    nginx         własny server block
    Nic z systemu tradingowego nie zostało zmienione ani użyte.

  KSeF 2.0 / FA(3)
    stan          CODE-COMPLETE · AUTOMATED-TESTED
                  LIVE-TEST-VERIFIED: NOT YET · DEMO-VERIFIED: NOT YET · PRODUCTION: OFF
    transport     KSEF_TRANSPORT=disabled (nic nie jest wysyłane ani pobierane)
    Gate 1        $KSEF_GATE1
    kopia         ${PRE_MIGRATE_DUMP:-brak (świeża baza)}
    dalej         Ustawienia → Poland → KSeF → Konfiguracja (krok po kroku),
                  potem bramki 2-14 z docs/KSEF_PRODUCTION_GATE.md — po kolei.

  NASTĘPNY KROK — WYMAGANY PRZED PŁACENIEM PODATKU
    POLAND_REQUIRE_OFFICIAL_RATES=true — rozliczenia są ZABLOKOWANE do czasu
    potwierdzenia stawek w źródłach urzędowych. To jest zamierzone: system
    odmawia zamiast podać kwotę, której nikt nie sprawdził.

      cd $APP_ROOT/foundation
      $PHP_BIN artisan poland:rate-provenance --todo

    Po potwierdzeniu przez księgowego ustaw status "official" w
    config/rates/*.php i uruchom ponownie poland:rate-provenance.

    Aby przed weryfikacją zobaczyć wyliczenia ORIENTACYJNE, ustaw świadomie
    POLAND_REQUIRE_OFFICIAL_RATES=false w $ENV_FILE — każdy ekran będzie
    wtedy oznaczony NOT VERIFIED i żadna kwota nie nadaje się do zapłaty.

  PRZYDATNE
    $PHP_BIN artisan poland:verify-rates
    $PHP_BIN artisan poland:report 2026-08 --sales=22150
    tail -f $APP_ROOT/foundation/storage/logs/laravel.log

################################################################################

SUMMARY
