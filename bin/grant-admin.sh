#!/usr/bin/env bash
#
# Grants an account access to the administration panel, and optionally sets its
# password.
#
#   sudo bash bin/grant-admin.sh admin@account.signalmesh.dev
#
# Why this exists. The installer creates a user, and a user is not an
# administrator: App\Models\User::canAccessPanel() refuses the 'admin' panel to
# anyone without the super_admin or admin role, and upstream grants that role
# only through database seeders that also create a demo account. So a correct
# password is rejected at /admin/login while the same account works at /login.
# This script creates the team, syncs the permissions, creates the role and
# assigns it — without seeding any demo user.
#
# The password is never passed on a command line or in an environment variable
# (both are visible in `ps`); it goes through a file that only root can read and
# is removed immediately afterwards.

set -euo pipefail

FOUNDATION="${FOUNDATION:-/srv/accounting/foundation}"
APP_USER="${APP_USER:-accounting}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.5}"
EMAIL="${1:-}"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die()  { printf '\n\033[1;31mBŁĄD: %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Uruchom przez sudo/root."
[ -n "$EMAIL" ] || die "Podaj adres e-mail konta: sudo bash $0 admin@account.signalmesh.dev"
[ -d "$FOUNDATION" ] || die "Nie znaleziono aplikacji w $FOUNDATION."
command -v "$PHP_BIN" >/dev/null || die "Nie znaleziono $PHP_BIN."

PW_FILE="$(mktemp)"; chmod 600 "$PW_FILE"
cleanup() { rm -f "$PW_FILE"; }
trap cleanup EXIT

step "Hasło"
printf '    Nowe hasło dla %s (Enter = nie zmieniaj; nic nie zostanie wyświetlone): ' "$EMAIL"
read -rs PASSWORD; echo
if [ -n "$PASSWORD" ]; then
    printf '    Powtórz hasło: '
    read -rs PASSWORD2; echo
    [ "$PASSWORD" = "$PASSWORD2" ] || die "Hasła nie są identyczne."
    [ "${#PASSWORD}" -ge 12 ] || die "Hasło musi mieć co najmniej 12 znaków."
    printf '%s' "$PASSWORD" > "$PW_FILE"
    info "hasło zostanie zmienione"
else
    info "hasło bez zmian"
fi
unset PASSWORD PASSWORD2
chown "$APP_USER" "$PW_FILE"

step "Uprawnienia (permissions:sync)"
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" permissions:sync >/dev/null 2>&1 \
    && info "uprawnienia zsynchronizowane" \
    || info "polecenie permissions:sync niedostępne — pomijam (rola i tak powstanie)"

step "Zespół, rola i przypisanie"
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" tinker --execute "
\$email = '$EMAIL';
\$pwFile = '$PW_FILE';

\$user = App\Models\User::where('email', \$email)->first();
if (! \$user) { echo \"NIE ZNALEZIONO KONTA {\$email}\n\"; exit(1); }

\$password = is_file(\$pwFile) ? file_get_contents(\$pwFile) : '';
if (\$password !== '') {
    \$user->forceFill(['password' => Illuminate\Support\Facades\Hash::make(\$password)])->save();
    echo \"haslo zmienione\n\";
}
if (\$user->email_verified_at === null) {
    \$user->forceFill(['email_verified_at' => now()])->save();
    echo \"adres e-mail oznaczony jako zweryfikowany\n\";
}

// A team must exist before a team-scoped role can be created, and the panel
// resolves the tenant from the user's current team.
\$team = App\Models\Team::firstOrCreate(
    ['name' => 'Default', 'personal_team' => false],
    ['user_id' => \$user->id],
);
\$user->forceFill(['current_team_id' => \$team->id])->save();
\$user->teams()->syncWithoutDetaching([\$team->id]);
echo \"zespol: {\$team->name} (id {\$team->id})\n\";

\$roleName = (string) config('filament-shield.super_admin.name', 'super_admin');
\$roleData = ['name' => \$roleName, 'guard_name' => 'web'];

// Spatie roles are team-scoped only when Shield's tenancy is on. Setting the
// team id when it is off would write a column that does not exist.
if (class_exists(BezhanSalleh\FilamentShield\Support\Utils::class)
    && BezhanSalleh\FilamentShield\Support\Utils::isTenancyEnabled()) {
    \$roleData['team_id'] = \$team->id;
    app(Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(\$team->id);
    echo \"role sa przypisane do zespolu (tenancy wlaczone)\n\";
}

\$role = Spatie\Permission\Models\Role::firstOrCreate(\$roleData);
\$permissions = Spatie\Permission\Models\Permission::where('guard_name', 'web')->pluck('id')->toArray();
if (\$permissions !== []) { \$role->syncPermissions(\$permissions); }
echo \"rola {\$roleName}: \" . count(\$permissions) . \" uprawnien\n\";

\$user->assignRole(\$role);
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

\$user->refresh();
echo \$user->hasAdminAccess()
    ? \"WERYFIKACJA: konto ma dostep do panelu /admin\n\"
    : \"WERYFIKACJA NIEUDANA: konto nadal nie ma dostepu do /admin\n\";
" || die "Nadanie roli nie powiodło się — zobacz komunikat powyżej."

step "Czyszczenie pamięci podręcznej"
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" config:cache >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" cache:clear >/dev/null 2>&1 || true
info "gotowe"

cat <<SUMMARY

  Zaloguj się: https://account.signalmesh.dev/admin/login
               e-mail $EMAIL

  Jeśli weryfikacja powyżej mówi NIEUDANA, prześlij ten komunikat — rola
  istnieje, ale panel odrzuca konto z innego powodu.
SUMMARY
