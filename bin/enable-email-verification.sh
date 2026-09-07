#!/usr/bin/env bash
#
# Turns on email verification for new accounts.
#
# Fortify already has the feature enabled and the routes exist; what is missing
# is that the host application's User model does not implement MustVerifyEmail,
# so Laravel never sends the mail and the `verified` middleware is a no-op.
#
# This is a HOST APPLICATION change, applied deliberately and idempotently
# rather than silently at install time, because:
#   - it needs working SMTP, or every new registration dead-ends;
#   - the foundation is re-cloned on upgrade, so it must be re-applied and you
#     should know that it exists.
#
#   sudo bash bin/enable-email-verification.sh [/srv/accounting/foundation]

set -euo pipefail

TARGET="${1:-/srv/accounting/foundation}"
USER_MODEL="$TARGET/app/Models/User.php"
ENV_FILE="$TARGET/.env"

die() { printf '\033[31mBŁĄD:\033[0m %s\n' "$*" >&2; exit 1; }
say() { printf '\033[1m==>\033[0m %s\n' "$*"; }

[ -f "$USER_MODEL" ] || die "Nie znaleziono $USER_MODEL"

if grep -q "MustVerifyEmail" "$USER_MODEL"; then
    say "Weryfikacja e-mail jest już włączona w modelu User."
else
    say "Włączam MustVerifyEmail w modelu User"
    cp "$USER_MODEL" "$USER_MODEL.bak.$(date +%Y%m%d%H%M%S)"

    # Anchor-safe: abort rather than guess if the class line is not what we expect.
    grep -qE '^class User extends Authenticatable implements ' "$USER_MODEL" \
        || die "Deklaracja klasy User wygląda inaczej niż oczekiwano — przerywam zamiast zgadywać."

    sed -i \
        -e 's|^use Illuminate\\Foundation\\Auth\\User as Authenticatable;|use Illuminate\\Contracts\\Auth\\MustVerifyEmail;\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;|' \
        -e 's|^class User extends Authenticatable implements |class User extends Authenticatable implements MustVerifyEmail, |' \
        "$USER_MODEL"

    php -l "$USER_MODEL" >/dev/null || die "Po zmianie plik nie jest poprawny — przywróć kopię .bak"
fi

say "Sprawdzam konfigurację poczty"
if grep -qE '^MAIL_MAILER=(log|array)$' "$ENV_FILE" 2>/dev/null; then
    cat <<'WARN'

    UWAGA: MAIL_MAILER jest ustawiony na "log" — wiadomości NIE będą wysyłane,
    tylko zapisywane do storage/logs/laravel.log. Nowy użytkownik nie dostanie
    linku i nie potwierdzi konta.

    Ustaw prawdziwy SMTP w .env, na przykład:

      MAIL_MAILER=smtp
      MAIL_HOST=smtp.twojdostawca.pl
      MAIL_PORT=587
      MAIL_USERNAME=...
      MAIL_PASSWORD=...
      MAIL_ENCRYPTION=tls
      MAIL_FROM_ADDRESS=konto@account.signalmesh.dev
      MAIL_FROM_NAME="SignalMesh Accounts"

WARN
fi

say "Czyszczę cache konfiguracji"
( cd "$TARGET" && php artisan config:clear >/dev/null && php artisan config:cache >/dev/null )

cat <<'DONE'

Gotowe. Od teraz:
  - nowa rejestracja na /register wysyła link potwierdzający,
  - użytkownik trafia na /email/verify do czasu kliknięcia w link,
  - konta założone WCZEŚNIEJ mają już ustawione email_verified_at i działają.

Testowa wysyłka:
  php artisan tinker --execute="App\Models\User::first()->sendEmailVerificationNotification();"

DONE
