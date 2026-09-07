#!/usr/bin/env bash
#
# The pre-live data-safety drill: back up, restore, and prove the accounting
# records survived.
#
# Run it BEFORE going live and monthly afterwards. A backup nobody has restored
# is a belief, not a backup, and the failure mode is silent until the day it
# matters.
#
#   sudo bash bin/data-safety-drill.sh [/srv/accounting/foundation]

set -euo pipefail

TARGET="${1:-/srv/accounting/foundation}"
ENV_FILE="$TARGET/.env"
[ -f "$ENV_FILE" ] || { echo "Nie znaleziono $ENV_FILE" >&2; exit 1; }

env_get() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"'; }
DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"
DB_PASS="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
MYSQL=(mysql --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS")

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
DUMP="$WORK/accounting.sql.gz"
VERIFY_DB="${DB_NAME}_drill"
FAILED=0

pass() { printf '\033[32m  PASS\033[0m %s\n' "$*"; }
fail() { printf '\033[31m  FAIL\033[0m %s\n' "$*"; FAILED=1; }
step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

# Everything the release gate requires to survive a restore.
TABLES=(users
        pl_tax_profiles
        pl_sales_reports pl_sales_report_lines pl_purchase_summaries
        pl_ksef_credentials pl_ksef_documents pl_ksef_sync_state
        pl_bank_statements pl_bank_transactions pl_transaction_classifications
        pl_government_documents
        pl_settlements pl_report_versions pl_prepared_documents
        pl_payment_obligations pl_audit_events)

step "1  Kopia zapasowa"
mysqldump --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" \
    --single-transaction --routines --triggers "$DB_NAME" | gzip > "$DUMP"
[ -s "$DUMP" ] && pass "zrzut wykonany ($(du -h "$DUMP" | cut -f1))" || fail "zrzut jest pusty"

step "2  Odtworzenie do bazy tymczasowej"
# The accounting user is deliberately scoped to its own database. The deploy
# script also grants it the "<db>_drill" scratch namespace, precisely so this
# check can run without widening access to anything else. If that grant is
# missing the drill fails loudly rather than being quietly skipped — an
# unrestored backup is a belief, not a backup.
if ! "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`; CREATE DATABASE \`$VERIFY_DB\`;" 2>/dev/null; then
    fail "brak uprawnien do bazy $VERIFY_DB — nadaj: GRANT ALL ON \`$VERIFY_DB\`.* TO '$DB_USER'@'$DB_HOST';"
    printf '\n\033[31mDRILL NIEZALICZONY — nie mozna sprawdzic odtworzenia.\033[0m\n\n'
    exit 1
fi
gunzip -c "$DUMP" | "${MYSQL[@]}" "$VERIFY_DB"
pass "odtworzono do $VERIFY_DB"

step "3  Zgodność liczby wierszy w tabelach księgowych"
for t in "${TABLES[@]}"; do
    SRC="$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$t\`" 2>/dev/null || echo missing)"
    DST="$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM \`$VERIFY_DB\`.\`$t\`" 2>/dev/null || echo missing)"
    [ "$SRC" = "$DST" ] && pass "$t: $SRC wierszy" || fail "$t: źródło $SRC, odtworzone $DST"
done

step "4  Raporty miesięczne odtworzone bez zmiany treści"
# Compares the stored checksum against a hash of the restored report body: a
# report that came back different is worse than one that did not come back.
BAD="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM \`$VERIFY_DB\`.pl_report_versions v
    JOIN \`$DB_NAME\`.pl_report_versions o
      ON o.id = v.id
    WHERE o.checksum <> v.checksum OR o.report <> v.report;" 2>/dev/null || echo error)"
[ "$BAD" = "0" ] && pass "wszystkie wersje raportów identyczne" || fail "$BAD wersji raportów różni się po odtworzeniu"

step "5  Ślad audytowy odtworzony w całości"
SRC="$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM \`$DB_NAME\`.pl_audit_events" 2>/dev/null || echo 0)"
DST="$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM \`$VERIFY_DB\`.pl_audit_events" 2>/dev/null || echo 0)"
[ "$SRC" = "$DST" ] && pass "$SRC zdarzeń" || fail "audyt: $SRC vs $DST"

step "6  Ochrona przed duplikatem raportu miesięcznego"
DUP="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM (
        SELECT tax_profile_id, period, COUNT(*) c
        FROM \`$DB_NAME\`.pl_sales_reports WHERE status='recorded'
        GROUP BY tax_profile_id, period HAVING c > 1
    ) x;" 2>/dev/null || echo error)"
[ "$DUP" = "0" ] && pass "brak zduplikowanych raportów sprzedaży w mocy" || fail "$DUP zduplikowanych miesięcy"

DUPV="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM (
        SELECT tax_profile_id, period, version, COUNT(*) c
        FROM \`$DB_NAME\`.pl_report_versions
        GROUP BY tax_profile_id, period, version HAVING c > 1
    ) x;" 2>/dev/null || echo error)"
[ "$DUPV" = "0" ] && pass "brak zduplikowanych wersji raportu" || fail "$DUPV zduplikowanych wersji"

step "7  Oryginalne dokumenty KSeF odtworzone bez zmiany bajtu"
# Row counts prove nothing about content. The original XML is the document of
# record; a restore that returns a different one is worse than a restore that
# returns nothing, because nothing looks broken.
XML_BAD="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM \`$VERIFY_DB\`.pl_ksef_documents v
    JOIN \`$DB_NAME\`.pl_ksef_documents o ON o.id = v.id
    WHERE o.xml_checksum <> v.xml_checksum
       OR SHA2(o.original_xml, 256) <> SHA2(v.original_xml, 256);" 2>/dev/null || echo error)"
[ "$XML_BAD" = "0" ] && pass "oryginalny XML faktur identyczny" || fail "$XML_BAD faktur rozni sie po odtworzeniu"

step "8  Decyzje uzgodnieniowe zachowane"
DEC_BAD="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM \`$VERIFY_DB\`.pl_transaction_classifications v
    JOIN \`$DB_NAME\`.pl_transaction_classifications o ON o.id = v.id
    WHERE o.decision <> v.decision
       OR COALESCE(o.matched_document,'') <> COALESCE(v.matched_document,'');" 2>/dev/null || echo error)"
[ "$DEC_BAD" = "0" ] && pass "klasyfikacje i decyzje identyczne" || fail "$DEC_BAD decyzji rozni sie"

step "9  Kwoty rozliczen identyczne"
SUM_BAD="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM \`$VERIFY_DB\`.pl_settlements v
    JOIN \`$DB_NAME\`.pl_settlements o ON o.id = v.id
    WHERE o.total_due <> v.total_due OR o.zus_total <> v.zus_total
       OR o.pit_due <> v.pit_due OR o.vat_due <> v.vat_due;" 2>/dev/null || echo error)"
[ "$SUM_BAD" = "0" ] && pass "kwoty ZUS/PIT/VAT identyczne" || fail "$SUM_BAD rozliczen rozni sie"

step "10 Odtworzenie po nieudanym wyliczeniu"
# A settlement that threw must leave no half-written month behind.
ORPHAN="$("${MYSQL[@]}" -N -B -e "
    SELECT COUNT(*) FROM \`$DB_NAME\`.pl_payment_obligations o
    LEFT JOIN \`$DB_NAME\`.pl_settlements s ON s.id = o.settlement_id
    WHERE o.settlement_id IS NOT NULL AND s.id IS NULL;" 2>/dev/null || echo error)"
[ "$ORPHAN" = "0" ] && pass "brak osieroconych pozycji do zapłaty" || fail "$ORPHAN osieroconych pozycji"

"${MYSQL[@]}" -e "DROP DATABASE \`$VERIFY_DB\`;"

if [ "$FAILED" -eq 0 ]; then
    printf '\n\033[32mDRILL ZALICZONY — kopia zapasowa daje się odtworzyć, dane są spójne.\033[0m\n\n'
else
    printf '\n\033[31mDRILL NIEZALICZONY — NIE URUCHAMIAJ PRODUKCYJNIE.\033[0m\n\n'
fi
exit "$FAILED"
