#!/usr/bin/env bash
#
# Backs up the accounting database and storage, then VERIFIES the dump by
# restoring it into a scratch database and counting rows.
#
# An unverified backup is a belief, not a backup. The definition of done for
# this project requires a tested restore, so the test lives in the same script
# as the backup and runs every time.

set -euo pipefail

: "${DB_DATABASE:?ustaw DB_DATABASE}"
: "${DB_USERNAME:?ustaw DB_USERNAME}"
: "${DB_PASSWORD:?ustaw DB_PASSWORD}"
DB_HOST="${DB_HOST:-127.0.0.1}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/accounting}"
STORAGE_DIR="${STORAGE_DIR:-/srv/accounting/foundation/storage/app}"
KEEP_DAYS="${KEEP_DAYS:-30}"

STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
DUMP="$BACKUP_DIR/db-$STAMP.sql.gz"

echo "==> Zrzut bazy $DB_DATABASE"
mysqldump --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
    --single-transaction --routines --triggers --events \
    "$DB_DATABASE" | gzip > "$DUMP"

echo "==> Weryfikacja przez odtworzenie do bazy tymczasowej"
# The scratch namespace the installer grants to the least-privilege accounting
# user (deploy-contabo.sh: GRANT ... ON `<db>_drill`.*). The drill uses the same
# name. A different name here meant "Access denied" on the first real backup.
VERIFY_DB="${VERIFY_DB:-${DB_DATABASE}_drill}"
mysql --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
    -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`; CREATE DATABASE \`$VERIFY_DB\`;"
gunzip -c "$DUMP" | mysql --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" "$VERIFY_DB"

for table in pl_tax_profiles pl_sales_reports pl_settlements pl_audit_events; do
    SOURCE=$(mysql -N -B --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
        -e "SELECT COUNT(*) FROM \`$DB_DATABASE\`.\`$table\`" 2>/dev/null || echo missing)
    RESTORED=$(mysql -N -B --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
        -e "SELECT COUNT(*) FROM \`$VERIFY_DB\`.\`$table\`" 2>/dev/null || echo missing)

    if [ "$SOURCE" != "$RESTORED" ]; then
        echo "BŁĄD: $table — źródło $SOURCE, odtworzone $RESTORED" >&2
        exit 1
    fi
    echo "    $table: $SOURCE wierszy — zgodne"
done

mysql --host="$DB_HOST" --user="$DB_USERNAME" --password="$DB_PASSWORD" \
    -e "DROP DATABASE \`$VERIFY_DB\`;"

echo "==> Załączniki i dokumenty"
tar -czf "$BACKUP_DIR/storage-$STAMP.tar.gz" -C "$(dirname "$STORAGE_DIR")" "$(basename "$STORAGE_DIR")"

find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime "+$KEEP_DAYS" -delete
find "$BACKUP_DIR" -name 'storage-*.tar.gz' -mtime "+$KEEP_DAYS" -delete

echo "==> Kopia zapasowa wykonana i ZWERYFIKOWANA: $DUMP"
