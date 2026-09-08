#!/usr/bin/env bash
#
# Grants an account access to the administration panel, and optionally sets or
# generates its password.
#
#   sudo bash bin/grant-admin.sh admin@account.signalmesh.dev             # asks for a password (Enter = keep)
#   sudo bash bin/grant-admin.sh admin@account.signalmesh.dev --generate  # sets a random one and prints it once
#
# Why this exists. The installer creates a user, and a user is not an
# administrator: App\Models\User::canAccessPanel() refuses the 'admin' panel to
# anyone without the super_admin or admin role, and upstream grants that role
# only through database seeders that also create a demo account. So a correct
# password is rejected at /admin/login while the same account works at /login.
# This script creates the team, syncs the permissions, creates the role and
# assigns it, without seeding any demo user.
#
# Two lessons from the first real run are built in: the Team model does not
# list user_id as fillable, so it is written with forceFill(); and a generated
# password is printed on ANY exit once it has been applied, so a later failure
# can never leave an account whose password nobody knows.
#
# The password is never passed on a command line or in an environment variable
# (both are visible in `ps`); it goes through a file that only root can read and
# is removed immediately afterwards.

set -euo pipefail

FOUNDATION="${FOUNDATION:-/srv/accounting/foundation}"
APP_USER="${APP_USER:-accounting}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.5}"
EMAIL="${1:-}"
MODE="${2:-}"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run with sudo/root."
[ -n "$EMAIL" ] || die "Give the account's e-mail: sudo bash $0 admin@account.signalmesh.dev [--generate]"
[ -d "$FOUNDATION" ] || die "Application not found at $FOUNDATION."
command -v "$PHP_BIN" >/dev/null || die "$PHP_BIN not found."

PW_FILE="$(mktemp)"; chmod 600 "$PW_FILE"
GENERATED=""
PASSWORD_APPLIED="no"
LOG="$(mktemp)"

# Printed on every exit path. Once the password has been applied, it is shown
# even if a later step failed, because a password that was set and not shown
# is worse than any error.
finish() {
    rm -f "$PW_FILE" "$LOG"
    if [ "$PASSWORD_APPLIED" = "yes" ] && [ -n "$GENERATED" ]; then
        printf '\n  LOGIN      https://account.signalmesh.dev/admin/login\n'
        printf '  E-MAIL     %s\n' "$EMAIL"
        printf '  PASSWORD   %s\n' "$GENERATED"
        printf '\n  WRITE THIS PASSWORD DOWN NOW. It is stored only as a hash.\n\n'
    fi
}
trap finish EXIT

step "Password"
if [ "$MODE" = "--generate" ]; then
    GENERATED="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 16)"
    printf '%s' "$GENERATED" > "$PW_FILE"
    info "a new password has been generated; it is printed at the end"
else
    printf '    New password for %s (Enter = keep the current one; nothing is echoed): ' "$EMAIL"
    read -rs PASSWORD; echo
    if [ -n "$PASSWORD" ]; then
        printf '    Repeat the password: '
        read -rs PASSWORD2; echo
        [ "$PASSWORD" = "$PASSWORD2" ] || die "The two passwords differ."
        [ "${#PASSWORD}" -ge 12 ] || die "The password must be at least 12 characters."
        printf '%s' "$PASSWORD" > "$PW_FILE"
        info "the password will be changed"
    else
        info "password unchanged"
    fi
    unset PASSWORD; unset PASSWORD2 2>/dev/null || true
fi
chown "$APP_USER" "$PW_FILE"

step "Permissions (permissions:sync)"
if sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" permissions:sync >/dev/null 2>&1; then
    info "permissions synchronised"
else
    info "permissions:sync is not available here; skipped (the role is created regardless)"
fi

step "Team, role and assignment"
set +e
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" tinker --execute "
\$email = '$EMAIL';
\$pwFile = '$PW_FILE';

\$user = App\Models\User::where('email', \$email)->first();
if (! \$user) { echo \"ACCOUNT NOT FOUND: {\$email}\n\"; exit(1); }

\$password = is_file(\$pwFile) ? file_get_contents(\$pwFile) : '';
if (\$password !== '') {
    \$user->forceFill(['password' => Illuminate\Support\Facades\Hash::make(\$password)])->save();
    echo \"PASSWORD_APPLIED\n\";
}
if (\$user->email_verified_at === null) {
    \$user->forceFill(['email_verified_at' => now()])->save();
    echo \"e-mail marked as verified\n\";
}

// The Team model does not list user_id as fillable, so firstOrCreate() drops
// it and MySQL refuses the row. forceFill() bypasses the fillable list.
\$team = App\Models\Team::where('name', 'Default')->where('personal_team', false)->first();
if (! \$team) {
    \$team = new App\Models\Team();
    \$team->forceFill(['name' => 'Default', 'personal_team' => false, 'user_id' => \$user->id])->save();
    echo \"team created\n\";
}
\$user->forceFill(['current_team_id' => \$team->id])->save();
\$user->teams()->syncWithoutDetaching([\$team->id]);
echo \"team: {\$team->name} (id {\$team->id})\n\";

\$roleName = (string) config('filament-shield.super_admin.name', 'super_admin');
\$roleData = ['name' => \$roleName, 'guard_name' => 'web'];

// Spatie's permission.teams (true here) decides whether the pivot is
// team-scoped; Shield's tenancy (off here) does not. With teams on and no
// team context, assignRole() writes a null team_id or throws.
if ((bool) config('permission.teams', false)) {
    \$roleData['team_id'] = \$team->id;
    app(Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(\$team->id);
    echo \"roles are team-scoped (team id {\$team->id})\n\";
}

\$role = Spatie\Permission\Models\Role::firstOrCreate(\$roleData);
\$permissions = Spatie\Permission\Models\Permission::where('guard_name', 'web')->pluck('id')->toArray();
if (\$permissions !== []) { \$role->syncPermissions(\$permissions); }
echo \"role {\$roleName}: \" . count(\$permissions) . \" permissions\n\";

try {
    \$user->assignRole(\$role);
} catch (\Throwable \$e) {
    echo \"assignRole failed ({\$e->getMessage()}); writing the pivot row directly\n\";
    \$pivot = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
    \$row = ['role_id' => \$role->id, 'model_id' => \$user->getKey(), 'model_type' => \$user->getMorphClass()];
    if ((bool) config('permission.teams', false)) {
        \$row[(string) config('permission.column_names.team_foreign_key', 'team_id')] = \$team->id;
    }
    if (! Illuminate\Support\Facades\DB::table(\$pivot)->where(\$row)->exists()) {
        Illuminate\Support\Facades\DB::table(\$pivot)->insert(\$row);
    }
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

\$user->refresh();
echo \$user->hasAdminAccess()
    ? \"VERIFIED: the account can open the /admin panel\n\"
    : \"VERIFICATION FAILED: the account still cannot open /admin\n\";
" 2>&1 | tee "$LOG"
STATUS=${PIPESTATUS[0]}
set -e
grep -q '^PASSWORD_APPLIED$' "$LOG" && PASSWORD_APPLIED="yes"
[ "$STATUS" -eq 0 ] || die "Granting the role failed; see the message above. If a password was generated it is printed below anyway."

step "Cache"
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" config:cache >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" "$FOUNDATION/artisan" cache:clear >/dev/null 2>&1 || true
info "done"

if ! grep -q '^VERIFIED:' "$LOG"; then
    printf '\n  The role exists but the panel still refuses this account. Send the output above.\n'
fi
