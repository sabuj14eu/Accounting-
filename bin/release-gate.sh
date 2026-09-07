#!/usr/bin/env bash
#
# The final release gate. Runs every mechanically verifiable check and records
# measured numbers — no estimates.
#
#   bin/release-gate.sh [/srv/accounting/foundation]
#
# Exits non-zero if any gate fails. Checks that cannot be automated (a real-data
# pilot against an accountant's records) are reported as REQUIRES HUMAN and do
# not pass silently.

set -uo pipefail

TARGET="${1:-/srv/accounting/foundation}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
MODULE="$REPO_ROOT/modules/poland"

PASS=0; FAIL=0; HUMAN=0
pass()  { printf '  \033[32mPASS\033[0m  %s\n' "$*"; PASS=$((PASS+1)); }
fail()  { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAIL=$((FAIL+1)); }
human() { printf '  \033[33mHUMAN\033[0m %s\n' "$*"; HUMAN=$((HUMAN+1)); }
gate()  { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }

# --- 1. complete test suite -------------------------------------------------
gate "1  Complete test suite"
SUITE_OUT="$("$PHP_BIN" "$MODULE/vendor/bin/phpunit" --configuration "$MODULE/phpunit.xml" 2>&1)"
SUITE_LINE="$(printf '%s' "$SUITE_OUT" | grep -E "^(OK|Tests:|FAILURES|ERRORS)" | tail -1)"
if printf '%s' "$SUITE_OUT" | grep -q "^OK ("; then
    pass "$SUITE_LINE"
else
    fail "$SUITE_LINE"
    printf '%s\n' "$SUITE_OUT" | tail -20
fi
printf '        PHP %s\n' "$("$PHP_BIN" -r 'echo PHP_VERSION;')"

# --- 2. production configuration fails closed --------------------------------
gate "2  Production configuration fails closed"
ENV_FILE="$TARGET/.env"
check_env() {
    local key="$1" want="$2"
    local got
    got="$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d '\r')"
    if [ "$got" = "$want" ]; then pass "$key=$want"; else fail "$key is '${got:-unset}', expected '$want'"; fi
}
if [ -f "$ENV_FILE" ]; then
    check_env POLAND_REQUIRE_OFFICIAL_RATES true
    check_env KSEF_TRANSPORT_ENABLED false
    check_env KSEF_TRANSPORT disabled
else
    fail "no .env at $ENV_FILE"
fi

# The installer's own defaults must be the safe ones, or a fresh deploy is unsafe
# however carefully this one was configured.
grep -q "set_env POLAND_REQUIRE_OFFICIAL_RATES true" "$REPO_ROOT/bin/deploy-contabo.sh" \
    && pass "installer default: POLAND_REQUIRE_OFFICIAL_RATES=true" \
    || fail "installer does not default to fail-closed rates"
grep -q "set_env KSEF_TRANSPORT_ENABLED false" "$REPO_ROOT/bin/deploy-contabo.sh" \
    && pass "installer default: KSEF_TRANSPORT_ENABLED=false" \
    || fail "installer does not default KSeF transport off"

# --- 3-8, 11. domain gates, asserted by the suite ----------------------------
gate "3-8, 11  Domain gates"
run_gate() {
    local label="$1" filter="$2"
    local out
    out="$("$PHP_BIN" "$MODULE/vendor/bin/phpunit" --configuration "$MODULE/phpunit.xml" --filter "$filter" 2>&1)"
    if printf '%s' "$out" | grep -q "^OK ("; then
        pass "$label — $(printf '%s' "$out" | grep -oE 'OK \([0-9]+ tests?, [0-9]+ assertions?\)')"
    else
        fail "$label"
    fi
}
run_gate "3  BLOCKED semantics"          "ReleaseGateTest::test_gate_3"
run_gate "4  engine boundary"            "EngineBoundaryTest"
run_gate "5  report immutability"        "ReleaseGateTest::test_gate_5"
run_gate "6  bank completeness"          "ReleaseGateTest::test_gate_6"
run_gate "7  KSeF failure semantics"     "ReleaseGateTest::test_gate_7"
run_gate "8  OCR failure semantics"      "ReleaseGateTest::test_gate_8"
run_gate "11 historical reproducibility" "HistoricalReproducibilityTest"

# --- 9. security sweep -------------------------------------------------------
gate "9  Security sweep"
SECRET_HITS="$(grep -rIn --exclude-dir=vendor --exclude-dir=.git --exclude-dir=node_modules \
    --exclude='*.md' --exclude='release-gate.sh' \
    -E '(KSEF_TOKEN|DB_PASSWORD|API_KEY|SECRET|PRIVATE_KEY)=[A-Za-z0-9/_+=-]{8,}' \
    "$REPO_ROOT" 2>/dev/null | grep -v '=$' || true)"
[ -z "$SECRET_HITS" ] && pass "no credential literals committed" || { fail "credential literal committed"; printf '%s\n' "$SECRET_HITS" | head -5; }

TOKEN_LOG="$(grep -rIn --include='*.php' --exclude-dir=vendor \
    -E '(Log::|logger\(\)|error_log)\s*.{0,60}(reveal\(\)|\$token|token_encrypted)' \
    "$MODULE/src" 2>/dev/null || true)"
[ -z "$TOKEN_LOG" ] && pass "no token reaches a logger" || { fail "token reaches a logger"; printf '%s\n' "$TOKEN_LOG"; }

REVEALS="$(grep -rIn --include='*.php' --exclude-dir=vendor -E '->reveal\(\)' "$MODULE/src" 2>/dev/null | wc -l)"
printf '        reveal() call sites in src/: %s\n' "$REVEALS"
[ "$REVEALS" -le 2 ] && pass "reveal() confined to the transport boundary" || fail "reveal() used in $REVEALS places"

[ -f "$REPO_ROOT/.gitignore" ] && grep -q '^\.env$' "$REPO_ROOT/.gitignore" \
    && pass ".env is gitignored" || fail ".env is not gitignored"

# --- 10. backup and restore --------------------------------------------------
gate "10 Backup and restore"
if command -v mysql >/dev/null 2>&1 && [ -f "$ENV_FILE" ]; then
    if bash "$REPO_ROOT/bin/data-safety-drill.sh" "$TARGET" >/tmp/drill.log 2>&1; then
        pass "backup restored and verified ($(grep -c PASS /tmp/drill.log) checks)"
    else
        fail "restore drill failed — see /tmp/drill.log"
        tail -15 /tmp/drill.log
    fi
else
    fail "no database available to drill"
fi

# --- 12. real-data pilot -----------------------------------------------------
gate "12 Real-data pilot"
human "requires real bank statement, real invoices and an accountant's records"

# --- 13. honest UI -----------------------------------------------------------
gate "13 Honest UI"
run_gate "13 integration status panel" "IntegrationStatusTest"

# --- summary -----------------------------------------------------------------
printf '\n\033[1m== RESULT ==\033[0m\n'
printf '  passed: %d   failed: %d   requires human: %d\n\n' "$PASS" "$FAIL" "$HUMAN"

if [ "$FAIL" -gt 0 ]; then
    printf '\033[31mGATE FAILED — do not deploy.\033[0m\n\n'
    exit 1
fi

printf '\033[32mMechanical gates passed.\033[0m\n'
printf 'Milestone reached: LIVE APPLICATION.\n'
printf 'NOT reached: PRODUCTION ACCOUNTING (needs rate verification + real-data pilot).\n'
printf 'NOT reached: AUTOMATED FILING (deliberately disabled).\n\n'
exit 0
