#!/usr/bin/env bash
#
# KSeF Gate 1 — runtime verification on the deployed PHP 8.5 foundation.
#
#   sudo bash bin/ksef-runtime-check.sh [/srv/accounting/foundation]
#
# The unit suite proves the framework-free core; it cannot prove that the
# Laravel layer boots, migrates and answers on THIS server. This script asks
# the running application, prints PASS / FAIL / HUMAN per item and exits
# non-zero on any FAIL. It reads no secret and prints none: the only .env keys
# it looks at are APP_URL and the KSEF_* switches.
#
# HUMAN items are the screens: a script can prove a route answers, not that a
# person sees the right page. They are listed so nobody ticks them by habit.

set -uo pipefail

TARGET="${1:-/srv/accounting/foundation}"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php)}"
ENV_FILE="$TARGET/.env"

PASS=0; FAIL=0; HUMAN=0
pass()  { printf '  \033[32mPASS\033[0m  %s\n' "$*"; PASS=$((PASS+1)); }
fail()  { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAIL=$((FAIL+1)); }
human() { printf '  \033[33mHUMAN\033[0m %s\n' "$*"; HUMAN=$((HUMAN+1)); }
gate()  { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }
env_get() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d '\r'; }

[ -f "$TARGET/artisan" ] || { echo "Brak $TARGET/artisan — podaj katalog foundation." >&2; exit 2; }
cd "$TARGET"
artisan() { "$PHP_BIN" artisan "$@"; }

# --- 1. boot ------------------------------------------------------------------
gate "1  Application boots on PHP 8.5"
if "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);'; then
    pass "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')"
else
    fail "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') — the foundation requires 8.5"
fi
if VERSION="$(artisan --version 2>&1)"; then pass "artisan boots: $VERSION"; else fail "artisan does not boot: $VERSION"; fi

# --- 2. migrations ------------------------------------------------------------
gate "2  Migrations applied"
STATUS="$(artisan migrate:status 2>&1)"
if printf '%s' "$STATUS" | grep -qiE 'pending'; then
    fail "pending migrations:"; printf '%s\n' "$STATUS" | grep -iE 'pending' | sed 's/^/        /'
else
    pass "no pending migrations"
fi
if printf '%s' "$STATUS" | grep -E '2026_09_08_000100_create_poland_ksef_integration_tables' | grep -qiE '\bran\b'; then
    pass "KSeF migration 2026_09_08_000100 ran"
else
    fail "KSeF migration 2026_09_08_000100 not recorded as ran"
fi

# --- 3. tables and constraints -----------------------------------------------
gate "3  Eight KSeF tables, unique constraints and indexes"
SCHEMA_REPORT="$(artisan tinker --execute '
$tables = ["pl_ksef_auth_sessions","pl_ksef_invoice_documents","pl_ksef_submissions","pl_ksef_status_events","pl_ksef_sync_cursors","pl_ksef_sync_runs","pl_ksef_errors","pl_ksef_customer_identifiers"];
foreach ($tables as $t) { echo "TABLE ", $t, " ", (Schema::hasTable($t) ? "OK" : "MISSING"), PHP_EOL; }
$unique = [
  ["pl_ksef_submissions", ["active_key"]],
  ["pl_ksef_submissions", ["invoice_reference"]],
  ["pl_ksef_submissions", ["ksef_number"]],
  ["pl_ksef_auth_sessions", ["reference_number"]],
  ["pl_ksef_sync_cursors", ["tax_profile_id","environment","subject_type"]],
  ["pl_ksef_customer_identifiers", ["tax_profile_id","erp_customer_id"]],
];
foreach ($unique as [$t, $cols]) {
  $found = false;
  if (Schema::hasTable($t)) { foreach (Schema::getIndexes($t) as $i) { if (($i["unique"] ?? false) && array_map("strtolower", $i["columns"]) === $cols) { $found = true; } } }
  echo "UNIQUE ", $t, "(", implode(",", $cols), ") ", ($found ? "OK" : "MISSING"), PHP_EOL;
}
$indexed = [["pl_ksef_status_events","occurred_at"],["pl_ksef_invoice_documents","xml_hash"],["pl_ksef_errors","occurred_at"]];
foreach ($indexed as [$t, $col]) {
  $found = false;
  if (Schema::hasTable($t)) { foreach (Schema::getIndexes($t) as $i) { if (in_array($col, array_map("strtolower", $i["columns"]), true)) { $found = true; } } }
  echo "INDEX ", $t, "(", $col, ") ", ($found ? "OK" : "MISSING"), PHP_EOL;
}
' 2>&1)"
if [ -z "$SCHEMA_REPORT" ] || ! printf '%s' "$SCHEMA_REPORT" | grep -q '^TABLE '; then
    fail "schema inspection did not run:"; printf '%s\n' "$SCHEMA_REPORT" | tail -5 | sed 's/^/        /'
else
    while IFS= read -r line; do
        case "$line" in
            *" OK")      pass "$line" ;;
            *" MISSING") fail "$line" ;;
        esac
    done <<< "$SCHEMA_REPORT"
fi

# --- 4. commands, routes, pages ----------------------------------------------
gate "4  Console commands, routes and pages registered"
COMMANDS="$(artisan list --raw 2>/dev/null)"
for c in poland:ksef-test poland:ksef-sync poland:ksef-poll poland:ksef-health; do
    printf '%s' "$COMMANDS" | grep -q "^$c" && pass "command $c" || fail "command $c missing"
done
ROUTES="$(artisan route:list 2>/dev/null)"
for r in poland.ksef.status poland.ksef.test poland.ksef.sync poland.ksef.wizard poland.ksef.submissions poland.ksef.submissions.send; do
    printf '%s' "$ROUTES" | grep -q "$r" && pass "route $r" || fail "route $r missing"
done
printf '%s' "$ROUTES" | grep -q 'poland-ksef' && pass "Filament page poland-ksef (Settings → Poland → KSeF)" || fail "Filament page poland-ksef not registered — was overlay/app copied into the foundation?"

# --- 5. health from stored facts ---------------------------------------------
gate "5  Health check runs and tells the truth about the transport"
TRANSPORT="$(env_get KSEF_TRANSPORT)"; ENABLED="$(env_get KSEF_TRANSPORT_ENABLED)"; KENV="$(env_get KSEF_ENVIRONMENT)"
printf '        KSEF_ENVIRONMENT=%s KSEF_TRANSPORT=%s KSEF_TRANSPORT_ENABLED=%s\n' "${KENV:-unset}" "${TRANSPORT:-unset}" "${ENABLED:-unset}"
HEALTH_JSON="$(artisan poland:ksef-health --json 2>&1)"; HEALTH_RC=$?
if printf '%s' "$HEALTH_JSON" | grep -q '"overall"'; then
    OVERALL="$(printf '%s' "$HEALTH_JSON" | grep -oE '"overall": *"[^"]+"' | head -1)"
    pass "poland:ksef-health runs (exit $HEALTH_RC, $OVERALL)"
    if [ "${TRANSPORT:-disabled}" = "disabled" ] || [ "${ENABLED:-false}" != "true" ]; then
        if printf '%s' "$OVERALL" | grep -q 'NOT CONNECTED' && [ "$HEALTH_RC" -ne 0 ]; then
            pass "transport off → NOT CONNECTED and non-zero exit (a lost connection never reads as healthy)"
        else
            fail "transport off but health did not report NOT CONNECTED with a non-zero exit"
        fi
    fi
    if printf '%s' "$HEALTH_JSON" | grep -qiE 'bearer |eyJ[A-Za-z0-9_-]{20,}'; then fail "health output contains token-like material"; else pass "health output contains no token-like material"; fi
else
    fail "poland:ksef-health did not produce JSON:"; printf '%s\n' "$HEALTH_JSON" | tail -5 | sed 's/^/        /'
fi

# --- 6. scheduler and queue ---------------------------------------------------
gate "6  Scheduler and queue can poll submitted invoices"
SCHEDULE="$(artisan schedule:list 2>&1)"
if [ "${ENABLED:-false}" = "true" ] && [ "${TRANSPORT:-disabled}" = "real" ]; then
    printf '%s' "$SCHEDULE" | grep -q 'poland:ksef-poll' && pass "poland:ksef-poll is scheduled" || fail "transport enabled but poland:ksef-poll is not scheduled"
else
    printf '%s' "$SCHEDULE" | grep -q 'poland:ksef-poll' && fail "transport off but poland:ksef-poll is scheduled" || pass "transport off → poland:ksef-poll not scheduled (by design; it appears once the transport is enabled)"
fi
if [ "${KSEF_CHECK_SKIP_SYSTEMD:-0}" = "1" ]; then
    human "systemd units skipped (KSEF_CHECK_SKIP_SYSTEMD=1, e.g. CI) — confirm accounting-scheduler.timer and accounting-queue.service on the server"
elif command -v systemctl >/dev/null 2>&1; then
    for unit in accounting-scheduler.timer accounting-queue.service; do
        if systemctl is-active --quiet "$unit"; then pass "$unit active"; else fail "$unit not active"; fi
    done
else
    human "no systemctl here — confirm accounting-scheduler.timer and accounting-queue.service are active"
fi
if artisan poland:ksef-poll --help >/dev/null 2>&1; then pass "poland:ksef-poll is invocable"; else fail "poland:ksef-poll --help failed"; fi

# --- 7. HTTP surface ----------------------------------------------------------
gate "7  HTTP surface answers"
APP_URL="$(env_get APP_URL)"
if [ "${KSEF_CHECK_SKIP_HTTP:-0}" = "1" ]; then
    human "HTTP surface skipped (KSEF_CHECK_SKIP_HTTP=1, e.g. CI without a web server) — open the pages on the server"
elif [ -n "$APP_URL" ] && command -v curl >/dev/null 2>&1; then
    for path in /poland/ksef /poland/ksef/wizard/1 /poland/ksef/submissions /login; do
        CODE="$(curl -sS -o /dev/null -m 20 -w '%{http_code}' "$APP_URL$path" 2>/dev/null || echo 000)"
        case "$CODE" in
            200|302) pass "$path → HTTP $CODE (route answers; rendering is a HUMAN check below)" ;;
            000)     fail "$path → no answer from $APP_URL" ;;
            5*)      fail "$path → HTTP $CODE — see storage/logs/laravel.log" ;;
            *)       fail "$path → HTTP $CODE" ;;
        esac
    done
else
    human "APP_URL unset or curl missing — open the pages by hand"
fi

# --- 8. screens a person must see --------------------------------------------
gate "8  Screens (log in and look — a 302 is not a rendered page)"
human "Settings → Poland → KSeF renders and shows NOT CONNECTED with the transport off"
human "Konfiguracja wizard: each step renders, saved values reappear after reload, the token is shown only as a fingerprint"
human "Testuj połączenie with the transport off refuses with INTEGRATION_DISABLED — never '0 faktur'"
human "Sales Orders list shows the KSeF header block; an invoice's edit page shows the KSeF panel"

# --- summary ------------------------------------------------------------------
printf '\n\033[1m== KSeF GATE 1 (runtime) ==\033[0m\n'
printf '  passed: %d   failed: %d   requires human: %d\n\n' "$PASS" "$FAIL" "$HUMAN"
if [ "$FAIL" -gt 0 ]; then
    printf '\033[31mGATE 1 FAILED — record the failures in docs/KSEF_PRODUCTION_GATE.md; do not proceed to Gate 2.\033[0m\n\n'
    exit 1
fi
printf '\033[32mMechanical Gate 1 checks passed.\033[0m The %d HUMAN rows above are still open until a person looked.\n' "$HUMAN"
printf 'Status stays: CODE-COMPLETE · AUTOMATED-TESTED · LIVE-TEST-VERIFIED: NOT YET · DEMO-VERIFIED: NOT YET · PRODUCTION: OFF\n\n'
exit 0
