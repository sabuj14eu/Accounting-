#!/usr/bin/env bash
#
# Fails if the accounting application has grown a dependency on the trading
# system. Run it in CI and before every deploy.
#
# The isolation requirement is the one rule in this project that cannot be
# checked by reading a diff once and trusting it afterwards: it is violated by
# ADDING something, at any time, in any file. So it is checked mechanically.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

FAILED=0
report() { printf '\033[31mNARUSZENIE IZOLACJI:\033[0m %s\n' "$*"; FAILED=1; }
ok()     { printf '\033[32mOK:\033[0m %s\n' "$*"; }

SCAN_PATHS=(modules shop-intelligence docs bin deploy overlay .env.example composer.json README.md)
EXISTING=()
for path in "${SCAN_PATHS[@]}"; do [ -e "$path" ] && EXISTING+=("$path"); done

# 1. No reference to trading services, brokers or the trading brain.
#
# Whole-line comments are excluded: this file and .env.example both name the
# forbidden systems in order to forbid them, and a check that cannot tell a
# prohibition from a violation gets switched off within a week. Code, keys and
# values are what is scanned.
FORBIDDEN='sniper-bot|sniper_bot|SniperExecutor|MT5|metatrader|brother_sniper|brother-brain|Trade Desk'
HITS=$(grep -rEIn --exclude-dir=vendor --exclude-dir=.git --exclude='check-isolation.sh' --exclude='check-shop-isolation.sh' \
        --exclude='AiAction.php' --exclude='IsolationTest.php' \
        --exclude='*.md' -- "$FORBIDDEN" "${EXISTING[@]}" 2>/dev/null \
        | grep -Ev ':[0-9]+:[[:space:]]*(#|//|\*|/\*)')
if [ -n "$HITS" ]; then
    echo "$HITS"
    report "znaleziono odwołanie do systemu tradingowego (wzorzec: $FORBIDDEN)"
else
    ok "brak odwołań do bota, brokera i brain-a poza komentarzami zakazującymi"
fi

# 2. No trading host may appear as a dependency. app.signalmesh.dev may only
#    ever be linked TO, never called, so any occurrence outside documentation
#    and the navigation snippet is a failure.
HOST_HITS=$(grep -rIn --exclude-dir=vendor --exclude-dir=.git --exclude-dir=docs \
        --exclude='check-isolation.sh' --exclude='*.md' -- 'app\.signalmesh\.dev' \
        "${EXISTING[@]}" 2>/dev/null | grep -Ev ':[0-9]+:[[:space:]]*(#|//|\*|/\*)')
if [ -n "$HOST_HITS" ]; then
    echo "$HOST_HITS"
    report "kod odwołuje się do hosta tradingowego app.signalmesh.dev"
else
    ok "kod nie odwołuje się do hosta tradingowego poza komentarzami"
fi

# 3. No secret may be committed.
if grep -rEIn --exclude-dir=vendor --exclude-dir=.git --exclude='check-isolation.sh' --exclude='check-shop-isolation.sh' \
        --exclude='AiAction.php' --exclude='IsolationTest.php' \
        --exclude='*.md' -- '(KSEF_TOKEN|DB_PASSWORD|API_KEY|SECRET)=[^[:space:]"]+' \
        "${EXISTING[@]}" 2>/dev/null | grep -v '=$'; then
    report "w repozytorium znajduje się wartość sekretu"
else
    ok "brak wartości sekretów w repozytorium"
fi

# 4. The tax engine must stay framework-free, so it keeps working when the
#    application does not.
if grep -rIn --include='*.php' -- 'Illuminate\\\|use Illuminate' \
        modules/poland/src/Domain modules/poland/src/Calculators \
        modules/poland/src/Rates modules/poland/src/Reporting 2>/dev/null; then
    report "silnik podatkowy zaczął zależeć od Laravela"
else
    ok "silnik podatkowy pozostaje niezależny od frameworka"
fi

if [ "$FAILED" -eq 0 ]; then
    printf '\n\033[32mIzolacja od systemu tradingowego zachowana.\033[0m\n'
else
    printf '\n\033[31mIzolacja naruszona — nie wdrażaj.\033[0m\n'
fi

exit "$FAILED"
