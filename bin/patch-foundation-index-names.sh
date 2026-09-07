#!/usr/bin/env bash
#
# Shortens over-long index names in the foundation's migrations.
#
# WHY THIS EXISTS
# At the pinned Liberu commit, 92 index names derived by Laravel exceed the 64-
# character identifier limit of MySQL/MariaDB (63 on PostgreSQL). `php artisan
# migrate` therefore fails PARTWAY THROUGH on those databases, leaving a
# half-created schema. Found by the release gate, on the very database the
# installer provisions.
#
# The rewrite is mechanical and lossless — index names carry no meaning beyond
# uniqueness within their table — and deterministic, so re-running produces an
# identical schema.
#
# This patches the FOUNDATION, which is re-cloned on upgrade, so it must run
# again after every upgrade; bin/install-foundation.sh does that automatically.
# It is also an upstream bug and should be reported there.
#
#   bin/patch-foundation-index-names.sh [/srv/accounting/foundation] [--check]

set -euo pipefail

TARGET="${1:-/srv/accounting/foundation}"
shift || true

PHP_BIN="${PHP_BIN:-php}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -d "$TARGET" ] || { echo "Nie znaleziono katalogu: $TARGET" >&2; exit 2; }

exec "$PHP_BIN" "$HERE/support/fix-index-names.php" "$TARGET" "$@"
