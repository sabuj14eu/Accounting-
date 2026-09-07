#!/usr/bin/env bash
#
# Installs the Liberu accounting ERP as the foundation of this application and
# mounts the Poland module into it.
#
# The foundation is INSTALLED, not vendored into this repository. Keeping
# upstream out of our git history is what makes it upgradable: a new upstream
# release is a change to FOUNDATION_REF here, not a 7 000-file merge.
#
# Usage:
#   bin/install-foundation.sh [target-directory]
#
# Defaults to ./foundation, which .gitignore already excludes.

set -euo pipefail

FOUNDATION_REPO="https://github.com/liberusoftware/accounting-erp-laravel"

# Pinned so a deployment is reproducible. Verified installable on 2026-09-07.
FOUNDATION_REF="3a23437a432c74637aca56bb0daed27430d481ee"

# Verified working combination (booted, migrated and exercised 2026-09-07):
#   PHP 8.5.0 - Laravel 13.29.0 - Filament v5.7.6 - Livewire v4.4.3
#   Liberu accounting-erp-laravel @ 3a23437 - 674 composer packages
#   288 migrations applied, including this module's five.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-$REPO_ROOT/foundation}"

say() { printf '\033[1m==>\033[0m %s\n' "$*"; }
die() { printf '\033[31mBŁĄD:\033[0m %s\n' "$*" >&2; exit 1; }

# --- Prerequisites ----------------------------------------------------------
# The foundation uses #[\Override] on properties, which is PHP 8.5 syntax. On
# 8.4 it does not fail at runtime — it fails to PARSE, before any autoloader
# runs, which makes the error look unrelated to the PHP version. Checking here
# turns a confusing fatal into a clear one.
command -v php >/dev/null || die "Nie znaleziono PHP."
command -v composer >/dev/null || die "Nie znaleziono Composera."
command -v git >/dev/null || die "Nie znaleziono gita."

PHP_OK=$(php -r 'echo PHP_VERSION_ID >= 80500 ? "yes" : "no";')
if [ "$PHP_OK" != "yes" ]; then
    die "$(php -r 'echo PHP_VERSION;') jest za stare. Liberu ERP wymaga PHP >= 8.5 (używa
      atrybutu #[\\Override] na właściwościach). Moduł Poland działa na PHP >= 8.2,
      ale aplikacja hosta nie wystartuje bez 8.5."
fi

# --- Fetch the foundation ---------------------------------------------------
if [ -d "$TARGET/.git" ]; then
    say "Aktualizuję istniejącą instalację w $TARGET"
    git -C "$TARGET" fetch --depth 1 origin "$FOUNDATION_REF"
    git -C "$TARGET" checkout --detach FETCH_HEAD
else
    say "Klonuję Liberu ERP do $TARGET (ref $FOUNDATION_REF)"
    mkdir -p "$TARGET"
    git -C "$TARGET" init -q
    git -C "$TARGET" remote add origin "$FOUNDATION_REPO"
    git -C "$TARGET" fetch --depth 1 origin "$FOUNDATION_REF"
    git -C "$TARGET" checkout --detach FETCH_HEAD
fi

# --- Mount the Poland module ------------------------------------------------
say "Podpinam moduł Poland jako repozytorium ścieżkowe"
mkdir -p "$TARGET/modules"
rm -rf "$TARGET/modules/poland"
ln -s "$REPO_ROOT/modules/poland" "$TARGET/modules/poland"

php -r '
$path = $argv[1];
$json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

$already = false;
foreach ($json["repositories"] ?? [] as $repository) {
    if (($repository["url"] ?? null) === "modules/poland") { $already = true; break; }
}
if (! $already) {
    array_unshift($json["repositories"], [
        "type" => "path",
        "url" => "modules/poland",
        "options" => ["symlink" => true],
    ]);
}
$json["require"]["signalmesh/poland-accounting"] = "*";

file_put_contents(
    $path,
    json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
' "$TARGET/composer.json"

# --- Install ----------------------------------------------------------------
say "composer install (to potrwa — foundation ma ~670 pakietow)"
( cd "$TARGET" && composer install --no-interaction --prefer-dist --no-dev )

# Laravel discovers a package's service provider from a Composer script. Skipping
# scripts installs the module and silently never registers it: no commands, no
# routes, no migrations, and no error anywhere saying so. Run discovery
# explicitly rather than trusting the install to have done it.
say "package:discover"
( cd "$TARGET" && php artisan package:discover --ansi )

if ! ( cd "$TARGET" && php artisan list 2>/dev/null | grep -q 'poland:report' ); then
    die "Modul Poland zainstalowal sie, ale jego provider nie zostal zarejestrowany.
      Sprawdz extra.laravel.providers w modules/poland/composer.json oraz
      bootstrap/cache/packages.php. Nie migruj, dopoki to nie dziala."
fi

if [ ! -f "$TARGET/.env" ]; then
    say "Tworzę .env z szablonu tego repozytorium"
    cp "$REPO_ROOT/.env.example" "$TARGET/.env"
    ( cd "$TARGET" && php artisan key:generate )
    echo
    echo "  UZUPEŁNIJ $TARGET/.env przed migracją (baza danych, Redis)."
    echo "  Żaden sekret systemu tradingowego nie może się tam znaleźć."
fi

say "Gotowe. Następnie:"
echo "    cd $TARGET"
echo "    php artisan migrate"
echo "    php artisan poland:verify-rates"
echo "    php artisan poland:rate-provenance --todo   # co musi potwierdzic ksiegowy"
echo "    php artisan poland:report $(date -d 'last month' +%Y-%m 2>/dev/null || date +%Y-%m) --sales=48500"
