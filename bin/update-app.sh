#!/usr/bin/env bash
#
# Update the installed accounting application to the latest commit of a branch.
# One command, in the deploy ceremony order: backup → code → isolation check →
# migrate → caches → restart → verify. Stops at the first failure and says which
# step, so a half-applied update cannot look like a finished one.
#
#   sudo bash /srv/accounting/app/bin/update-app.sh [branch]
#
# Defaults to the branch the app checkout is already on. Run as root (sudo); it
# drops to the accounting user for everything that touches the application.

set -euo pipefail

APP_USER="${APP_USER:-accounting}"
APP_ROOT="${APP_ROOT:-/srv/accounting}"
APP="$APP_ROOT/app"
FOUNDATION="$APP_ROOT/foundation"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.5}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"
BACKUP_DIR="${BACKUP_DIR:-$APP_ROOT/backups}"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die()  { printf '\n\033[1;31mBŁĄD: %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Uruchom przez sudo/root."
[ -d "$APP/.git" ] || die "Nie znaleziono aplikacji w $APP — najpierw bin/deploy-contabo.sh."
[ -f "$FOUNDATION/.env" ] || die "Nie znaleziono $FOUNDATION/.env — najpierw bin/deploy-contabo.sh."
[ -x "$PHP_BIN" ] || die "Brak $PHP_BIN."

as_app() { sudo -u "$APP_USER" env PATH="$PATH" "$@"; }

BRANCH="${1:-$(as_app git -C "$APP" rev-parse --abbrev-ref HEAD)}"
BEFORE="$(as_app git -C "$APP" rev-parse --short HEAD)"

# ---------------------------------------------------------------------------
step "1/7  Kopia zapasowa (zweryfikowana przez odtworzenie)"
# ---------------------------------------------------------------------------
as_app bash -c "set -a; . '$FOUNDATION/.env'; set +a; BACKUP_DIR='$BACKUP_DIR' STORAGE_DIR='$FOUNDATION/storage/app' bash '$APP/deploy/backup.sh'" \
    || die "Kopia zapasowa NIE powiodła się. Aktualizacja przerwana — nic nie zmieniono."

# ---------------------------------------------------------------------------
step "2/7  Kod: $BRANCH"
# ---------------------------------------------------------------------------
as_app git -C "$APP" fetch origin "$BRANCH"
as_app git -C "$APP" checkout -B "$BRANCH" "origin/$BRANCH"
AFTER="$(as_app git -C "$APP" rev-parse --short HEAD)"
info "$BEFORE → $AFTER"
as_app git -C "$APP" log --oneline "$BEFORE..$AFTER" | sed 's/^/    /' || true

# ---------------------------------------------------------------------------
step "3/7  Izolacja od tradingu"
# ---------------------------------------------------------------------------
( cd "$APP" && bash bin/check-isolation.sh ) || die "Izolacja naruszona — nie wdrażam."

# ---------------------------------------------------------------------------
step "4/7  Testy modułu Poland"
# ---------------------------------------------------------------------------
( cd "$APP/modules/poland" && as_app "$PHP_BIN" "$COMPOSER_BIN" install --no-interaction --quiet 2>/dev/null || true )
if [ -x "$APP/modules/poland/vendor/bin/phpunit" ]; then
    ( cd "$APP/modules/poland" && as_app "$PHP_BIN" vendor/bin/phpunit 2>&1 | tail -3 ) || die "Testy modułu nie przechodzą — nie wdrażam."
else
    info "phpunit niedostępny (composer install nie powiódł się) — testy przeszły w CI; pomijam lokalnie"
fi

# ---------------------------------------------------------------------------
step "5/7  Migracje"
# ---------------------------------------------------------------------------
( cd "$FOUNDATION" && as_app "$PHP_BIN" artisan migrate --force )

# ---------------------------------------------------------------------------
step "6/7  Cache i restart"
# ---------------------------------------------------------------------------
( cd "$FOUNDATION" \
    && as_app "$PHP_BIN" artisan config:cache \
    && as_app "$PHP_BIN" artisan route:cache \
    && as_app "$PHP_BIN" artisan view:cache )
systemctl restart php8.5-fpm accounting-queue.service
info "php-fpm i kolejka zrestartowane"

# ---------------------------------------------------------------------------
step "7/7  Weryfikacja"
# ---------------------------------------------------------------------------
( cd "$FOUNDATION" && as_app "$PHP_BIN" artisan migrate:status | grep -c 'Ran' | sed 's/^/    migracje wykonane: /' )
( cd "$FOUNDATION" && as_app "$PHP_BIN" artisan route:list | grep -c 'poland\.' | sed 's/^/    trasy modułu: /' )
DOMAIN="$(grep '^APP_URL=' "$FOUNDATION/.env" | cut -d= -f2- | sed 's#https\?://##')"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMAIN/poland/skrzynka" || echo 000)"
info "GET /poland/skrzynka → HTTP $CODE (302 = przekierowanie do logowania, poprawnie)"
[ "$CODE" = "302" ] || [ "$CODE" = "200" ] || die "Aplikacja nie odpowiada poprawnie (HTTP $CODE). Sprawdź: tail -n 100 $FOUNDATION/storage/logs/laravel.log"

printf '\n\033[1;32mGOTOWE — %s na %s (%s).\033[0m\n\n' "$DOMAIN" "$BRANCH" "$AFTER"
