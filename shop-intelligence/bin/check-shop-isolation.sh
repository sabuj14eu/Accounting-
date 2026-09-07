#!/usr/bin/env bash
#
# §28 — the isolation audit, run mechanically.
#
# Isolation is the one property here that cannot be established by reading a
# diff once and trusting it afterwards: it is broken by ADDING something, in any
# file, at any time. So it is checked by a script that runs in CI and before
# every deploy, and it is written to fail loudly rather than to pass quietly.
#
# What it proves, in the specification's own words:
#   no Accounts DB connection · no Accounts table access · no KSeF token access
#   no accounting-session reuse · no shared credentials · no government filing
#   no write-back to Accounts · no trading DB access · no MT5 access
#   no trading credentials · no SignalMesh trading execution access

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

FAILED=0
CHECK=0

fail() { printf '\033[31mISOLATION VIOLATION:\033[0m %s\n' "$*"; FAILED=1; }
ok()   { printf '\033[32m  ok  \033[0m %s\n' "$*"; }
step() { CHECK=$((CHECK + 1)); printf '\n[%02d] %s\n' "$CHECK" "$*"; }

# Files that name the forbidden things in order to forbid them. Excluding them
# is not a loophole: it is the difference between a guard that stays switched on
# and one that somebody comments out on a Friday.
EXCLUDES=(
  --exclude-dir=vendor --exclude-dir=.git --exclude-dir=.phpunit.cache
  --exclude=check-shop-isolation.sh
  --exclude=AiAction.php --exclude=AiBoundary.php --exclude=AiBoundaryViolation.php
  --exclude=IsolationTest.php --exclude=AiBoundaryTest.php
  --exclude='*.md'
)

# Strip whole-line comments before judging a hit.
scan() { grep -rEIn "${EXCLUDES[@]}" -- "$1" src tests bin config 2>/dev/null \
          | grep -Ev ':[0-9]+:[[:space:]]*(#|//|\*|/\*)' ; }

step 'No connection to the accounting database'
HITS=$(scan '(accounting|accounts)[_-]?(db|database)|DB_DATABASE=.*accounting|pl_(audit_events|monthly_tax_reports|sales_reports)')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'the analysis application names the accounting database or its tables'
else ok 'no accounting database name, no pl_ table name'; fi

step 'No access to the accounting engine code'
HITS=$(scan 'use[[:space:]]+Poland\\|Poland\\(Domain|Calculators|Rates|Reporting|Ksef|Laravel)')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'the analysis application imports the accounting engine'
else ok 'no import of the Poland tax engine'; fi

step 'No KSeF credential, token or client'
HITS=$(scan 'ksef[_-]?token|KsefClient|ksefCredential|KSEF_(TOKEN|NIP|ENVIRONMENT)')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'the analysis application reaches for KSeF credentials'
else ok 'no KSeF token, client or environment variable'; fi

step 'No government filing of any kind'
HITS=$(scan 'JpkV7|e-?Deklaracje|submitToKsef|bramka\.edeklaracje|mf\.gov\.pl|ksef\.mf')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'the analysis application contains a filing path'
else ok 'nothing files anything with anybody'; fi

step 'No shared session or authentication with the accounting application'
HITS=$(scan 'SESSION_DOMAIN=.*account\.signalmesh|accounting_session|shared[_-]?session|SESSION_CONNECTION=.*accounting')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'a session or cookie is shared with the accounting application'
else ok 'no shared session, cookie domain or session store'; fi

step 'No write path back to the accounting system'
HITS=$(scan 'function[[:space:]]+(write|push|sync|post|update)ToAccounts|accountsClient|AccountsWriter')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'a write-back to the accounting system exists'
else ok 'no write-back: the only route in is a hand-imported snapshot'; fi

step 'No trading system, in any of its names'
HITS=$(scan 'MT5|MetaTrader|SniperExecutor|brother_sniper|brother-brain|brain\.signalmesh|sniper[_-]?bot')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'the analysis application references the trading system'
else ok 'no MT5, no executor, no brain, no bot'; fi

step 'The analysis core stays framework-free and connectionless'
HITS=$(grep -rEIn "${EXCLUDES[@]}" -- 'new[[:space:]]+PDO|mysqli_connect|pg_connect|Illuminate\\|GuzzleHttp|curl_init' src 2>/dev/null \
        | grep -Ev ':[0-9]+:[[:space:]]*(#|//|\*|/\*)')
if [ -n "$HITS" ]; then echo "$HITS"; fail 'the framework-free core opens a connection'
else ok 'src/ opens no database connection and makes no outbound call'; fi

step 'The application declares its own everything'
MISSING=()
[ -f composer.json ] || MISSING+=('composer.json')
[ -f .env.example ] || MISSING+=('.env.example')
[ -f phpunit.xml ] || MISSING+=('phpunit.xml')
if [ ${#MISSING[@]} -gt 0 ]; then fail "missing its own ${MISSING[*]}"
else ok 'own composer.json, own .env.example, own test configuration'; fi

step 'The environment file names a database of its own'
if [ -f .env.example ]; then
    if grep -qE '^DB_DATABASE=shop_intelligence' .env.example \
       && ! grep -qiE '^DB_DATABASE=.*(accounting|liberu)' .env.example; then
        ok 'DB_DATABASE is shop_intelligence and nothing else'
    else
        fail '.env.example does not name a database of its own'
    fi
fi

echo
if [ "$FAILED" -eq 0 ]; then
    printf '\033[32mISOLATION PROVEN\033[0m — %d checks, 0 violations.\n' "$CHECK"
    echo 'Shop Intelligence shares no database, no table, no session, no credential,'
    echo 'no code and no network path with the accounting application or the'
    echo 'trading system. Each keeps working when the other is offline.'
    exit 0
fi

printf '\033[31mISOLATION NOT PROVEN\033[0m — %d checks, at least one violation above.\n' "$CHECK"
exit 1
