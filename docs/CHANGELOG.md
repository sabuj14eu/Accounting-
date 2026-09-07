# Changelog

## 2026-09-07 (later) — P0 validation

### Schema
| Migration | Change |
|---|---|
| `2026_09_07_000500` | `pl_settlements`: adds `stage`, `rates_fit_for_filing`, `rate_provenance`, `prepared_at`. New table `pl_prepared_documents` with a unique `idempotency_key`. |

Migration note: additive and reversible. Existing settlements default to
`stage = calculated` and `rates_fit_for_filing = false`, which is the correct
reading of a row written before the distinction existed.

### Added
- Full rate provenance: every version now carries a version identifier, the
  meaning of each value, the source document, the URL it came from, **the
  official URL where it must be confirmed**, the publication date, the check
  date, the checker and notes. A version missing any of it fails to load.
- `POLAND_REQUIRE_OFFICIAL_RATES` — production refuses to settle on rates that
  have not been verified against the issuing authority.
- Calculation / preparation / filing separated into `SettlementStage`, with
  preparation refused for estimates and unverified rates, and filing refused
  without a reference.
- Ports for every external system (`DocumentPreparer`, `DocumentSubmitter`,
  `ExchangeRateProvider`, `SchemaRegistry`), bound by default to adapters that
  refuse rather than degrade.
- `SchemaRegistry` selects schema versions by the period being filed, so a
  correction to an old month uses the schema that was current then.
- `poland:rate-provenance` — prints the verification worklist.
- `docs/SUPPORTED_VERSIONS.md`, `docs/RATE_VERIFICATION.md`,
  `docs/DEFINITION_OF_DONE.md`.
- CI: PHP 8.5 in the matrix, and a `laravel-integration` job that installs the
  ERP, migrates, and exercises commands and routes.

### Fixed
Three bugs found by executing the Laravel layer, none visible to `php -l` or to
the engine's own suite:
- `RateProvenance` constructor parameters were declared in a different order
  than `fromArray()` passed them positionally. Now passed by name and pinned by
  a test.
- A sales correction inserted the new report before superseding the old one,
  violating the unique index that makes duplicate months impossible.
- The dashboard view assumed `$errors` is always bound, which holds only inside
  the `web` middleware group.
- `bin/install-foundation.sh` used `--no-scripts`, so `package:discover` never
  ran and the module installed without registering. It now runs discovery and
  verifies the provider registered before migrating.

### Verified
- PHP 8.5.0 built from source; Liberu boots (Laravel 13.29.0); 288 migrations
  applied; models, recorder, audit trail, three commands, `GET /poland` (200)
  and `POST /poland/sprzedaz` (302, stored, re-rendered) all executed.
- 106 tests, 268 assertions green.
- Isolation check passes clean and fails when a trading reference is introduced.

### Known
- Rates remain unverified against official sources. Every official Polish
  domain is refused by this environment's network policy (403 on CONNECT), so
  the reading requires a human with network access. See
  `docs/DEFINITION_OF_DONE.md` condition 6.


## 2026-09-07 — Phase 1

### Schema
New tables, all prefixed `pl_`. No existing table is modified; nothing in the
trading platform is touched.

| Migration | Tables |
|---|---|
| `2026_09_07_000100` | `pl_tax_profiles` |
| `2026_09_07_000200` | `pl_sales_reports`, `pl_sales_report_lines`, `pl_purchase_summaries` |
| `2026_09_07_000300` | `pl_settlements` |
| `2026_09_07_000400` | `pl_audit_events` |

Migration note: all four are additive and reversible. `php artisan migrate` on a
fresh accounting database, or on a Liberu installation that has never carried
this module. `pl_audit_events` refuses updates and deletes at the model level,
so a rollback drops the table rather than editing it.

### Added
- Poland tax engine: VAT, ZUS and PIT for JDG, with versioned 2025/2026 rate
  tables carrying sources and verification dates.
- Cash-register (kasa fiskalna) sales recording, with corrections that supersede
  rather than overwrite.
- Monthly settlement report with the full derivation, legal bases, deadlines
  shifted off weekends and holidays, and explicit estimate flags.
- `bin/pl-tax` standalone CLI; `poland:report` and `poland:verify-rates` artisan
  commands; `/poland` dashboard.
- Append-only audit trail.
- `bin/check-isolation.sh`, `bin/install-foundation.sh`, nginx and systemd
  configuration, verified backup script.
- 85 tests.

### Verified
- Full test suite green on PHP 8.4.19.
- Isolation check passes clean and fails when a trading reference is introduced.
- Liberu ERP upstream `3a23437` installs with `composer install --no-dev`.

### Known
- The Liberu foundation requires PHP 8.5 to boot; see `docs/DEPLOYMENT.md`.
- The Laravel layer of this module has not been executed for that reason. The
  engine underneath it is fully tested independently.
