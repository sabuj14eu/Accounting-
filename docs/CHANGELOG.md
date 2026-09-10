# Changelog

## 2026-09-10 (third) — installer fix found by CI

### Fixed
- `POLAND_RATES_PATH=` (empty, as `.env.example` ships it) resolved to a blank
  rate directory and every artisan command died with "Rate directory not
  found:". Found by the CI integration job once the installer got past
  composer. Empty now means the module's own tables, in both `config/poland.php`
  and the provider.
- `bin/install-foundation.sh`: the foundation ships a `composer.lock`, and after
  the module is added to `composer.json`, `composer install` exits 4 ("required
  package is not present in the lock file"). The `laravel-integration` CI job
  had been failing at that step on every push of this branch, including the
  docs-only one. The installer now runs a partial `composer update` for the
  path package alone, which keeps every upstream pin from the lock.
- `bin/deploy-contabo.sh` and the README now default to this branch.

### Known — recorded in OPEN_ITEMS
- CI `rate-coverage` is red because the ZUS tables end in January 2027 and the
  check now looks into February 2027. Needs official 2027 figures; not
  extrapolated.

## 2026-09-10 (second) — Stage B: review inbox, postings, optional inventory, Glovo settlement

Implements stage B of `docs/ACCOUNTING_WORKFLOW_AND_DATA_MODEL.md` (section 15).
Needs NO KSeF connection: every path is exercised with the FA fixtures. The
transport stays `disabled`; the inbox says NOT CONNECTED in as many words.

### Schema
| Migration | Change |
|---|---|
| `2026_09_10_000100` | `pl_ksef_documents`: `due_date`, `service_period_from/to`, `payment_form`, `paid_on_invoice` (nullable — absence is not "unpaid"), `supplier_account`, `payment_terms_json`, `source`, `approval_status` (+index), `approved_by/at`, `decision_note`, `corrects_document_id`, `invoice_fingerprint` (+index), `possible_duplicate_of`, `duplicate_reason`, `duplicate_decision` |
| `2026_09_10_000200` | new `pl_suppliers`, `pl_products` (`inventory_tracked` default false), `pl_product_aliases` (unique per supplier spelling), `pl_purchase_invoice_lines` (unique per document+line), `pl_purchase_postings` (**unique `ksef_document_id`** — one posting per document, enforced by the database) |
| `2026_09_10_000300` | new `pl_inventory_movements` (**unique (source_type, source_id, source_line_no)** — one movement per line, ever), `pl_inventory_counts` |
| `2026_09_10_000400` | new `pl_platform_settlements` (versioned; corrections supersede) |
| `2026_09_10_000500` | `pl_sales_report_lines`: `channel` (default `shop_register`), `source_type`, `source_id` |
| `2026_09_10_000600` | `pl_purchase_summaries`: `superseded_at` |

Migration note: all additive and reversible. Existing documents default to
`approval_status = imported` (they were imported before review existed and are
not retroactively queued); existing sales lines default to the shop channel.
Nothing is keyed by day (`test_no_table_or_query_is_keyed_by_day` scans the
migrations directory).

### Added — framework-free (tested here, 67 new tests)
- `Ksef\Parsing\InvoiceLine`, `PaymentTerms`, `CorrectionReference`;
  `FaInvoiceParser` reads `FaWiersz`, `Platnosc`, `DaneFaKorygowanej`, `OkresFa`.
  Absent VAT on a line is derived and **labelled derived**; an absent quantity
  stays MISSING_FIELD; lines are checked against the header per rate and a
  mismatch is visible, never repaired.
- `Purchases\ApprovalStatus` (state machine — nothing goes backwards from
  posted except by a correction), `ApprovalGate` / `ApprovalAssessment`,
  `PostingPlan` (amounts from the header by rate; VAT period never earlier than
  receipt and never past the statutory window — both from the VAT rate table;
  stock movements only for tracked lines; a one-sentence `describe()` the
  owner reads before tapping), `CostCategory` (KPiR columns 10–13),
  `ProductMapping`, `LineResolution`, `InvoiceFingerprint` (second duplicate
  wall), `RegisterResolution` (postings vs manual: never summed; both = CONFLICT
  = no register).
- `Inventory\StockUnit`, `StockQuantity` (integer thousandths, unit-safe),
  `MovementType`, `StockMovement`, `StockPosition` (NO_OPENING_COUNT /
  NOT_COUNTED / COUNTED; consumption only ever implied by a count).
- `Platforms\Platform`, `VatTreatment` (UNKNOWN records and reconciles but
  **refuses to post to VAT**), `SettlementStatus`, `PlatformSettlement`
  (expected payout, difference as a question with innocent explanations, sales
  lines by rate on the Glovo channel, VAT rows per treatment).
- `Domain\SalesChannel`; `SalesLine::$channel` (default shop register);
  `FiscalSalesReport::grossByChannel()`.
- `config/rates/vat.php`: `input_vat_deduction_following_periods` (3) and
  `_quarterly` (2) — art. 86 ust. 11, as data.
- Caveats: `DOCUMENTS_AWAITING_REVIEW`, `PURCHASE_REGISTER_CONFLICT`,
  `PLATFORM_VAT_TREATMENT_UNKNOWN`, `PLATFORM_PAYOUT_UNRECONCILED`.

### Added — Laravel layer (lints; exercised by the CI integration job, not here)
- `KsefIngestService::import` now stores lines, payment terms, the fingerprint,
  the correction link and the supplier; every INCOMING document enters
  `awaiting_review`; a fingerprint twin is imported, flagged, excluded.
- `InvoicePostingService` — review (plan + gate), approve (posting + movements +
  status + audit in ONE transaction), reject (reason required), register from
  postings. A correction posts as a **signed adjustment** linked to its original
  (FA correction amounts are differences); the original is never edited.
- `ProductCatalogService` (aliases, tracking toggle with old→new audit, opening
  count), `InventoryService` (position, counts with the book quantity stored),
  `PlatformSettlementService` (versioned record; writes the platform's sales
  lines ONCE onto the month's report).
- `SettlementRecorder::recordChannelLines` — one channel's lines replace only
  that channel; the other channels' lines are carried; a reason is required
  only when the same channel was already recorded. `supersedePurchaseSummary`
  resolves a register conflict in favour of postings.
- `LedgerRepository` resolves each month's purchase register from ONE source
  via `RegisterResolution`; `conflictsFor()` feeds the month close, which gains
  a `purchases` step (awaiting-review count, conflicts, platform caveats).
- Screens: `/poland/skrzynka` (inbox), `/poland/skrzynka/{id}` (what approving
  will do, approve/reject/map/duplicate), `/poland/produkty`, `/poland/platformy`;
  a navigation bar on all module pages with the inbox count; the dashboard
  shows the month's sales by channel.
- Audit actions: `ksef.invoice_awaiting_review`, `ksef.invoice_approved`,
  `ksef.invoice_rejected`, `purchase.posted`, `product.created`,
  `product.mapped`, `inventory.tracking_changed`, `inventory.movement_recorded`,
  `inventory.count_recorded`, `platform.settlement_recorded`,
  `purchases.summary_superseded`.

### Verified
- 376 tests, 1155 assertions, 0 failures on PHP 8.4.19 (was 309 / 874).
- `bin/check-isolation.sh` clean. Every PHP file lints.
- NOT verified here: the Laravel layer against a database (needs PHP 8.5 and
  the foundation — the `laravel-integration` CI job). Blade views were checked
  for balanced directives only.

### Known
- KSeF transport still `disabled` (stage C). Glovo statement importer not
  built (stage D) — the month is typed. JPK preparation not built (stage E).
  Trusted-supplier auto-approval not built (stage F).
- Foundation hardening (design section 14.3–14.5) is server work, not done.

## 2026-09-10 — direction change: automatic invoices, optional inventory, monthly sales (design only)

No code, schema, route, view or configuration changed. One document added.

### Added
- `docs/ACCOUNTING_WORKFLOW_AND_DATA_MODEL.md` — the owner's real workflow for
  the kebab shop and the architecture that follows from it: supplier invoices
  arrive automatically from KSeF (when the authorised transport exists and is
  enabled), go through a review inbox, and on approval post purchase + VAT and,
  only for products explicitly marked as tracked, stock movements; sales are
  entered monthly (shop + Glovo); Glovo is an accounting settlement source
  reconciled with the bank; VAT → JPK_V7 → PIT → ZUS → monthly report. Includes
  the privacy audit of this repository and of the pinned Liberu foundation, the
  staged migration plan, the required tests, and what exists / is missing /
  depends on the real KSeF transport.

### Cancelled
- The earlier proposal for a mandatory daily-sales schema. Nothing keyed by day
  will be built; the monthly `pl_sales_reports` model stays and gains a channel.

### Known
- KSeF HTTP transport still not implemented; the design is built so everything
  else can be developed and tested with fixtures before it exists.
- Two accountant decisions the model refuses to guess: Glovo VAT treatment
  (domestic invoice vs import of services) and the JPK document type for Glovo
  orders.

## 2026-09-07 (seventh) — Shop Profit Intelligence, the analysis core

A **second, separate application** in `shop-intelligence/`: a management
analysis tool for the shop, sharing no database, no table, no session, no
credential, no code and no network path with the accounting application or the
trading system. Each keeps working when the other is offline.

### The design decision underneath everything
A number carries its evidence in the type system rather than in a label beside
it on the screen. `EvidenceType` decides `Provenance` and `Certainty`, and
nothing lets a caller set them. So the specification's worked example — a 5 000
invoice settled 3 000 by bank and 2 000 by declared cash — comes out FULLY
ALLOCATED with the two halves still distinguishable, and no rendering of it can
read as "5 000 found in the bank".

### Rules enforced mechanically rather than by convention
- `ReviewFlag` refuses accusatory vocabulary in English and Polish, and refuses
  to exist without innocent explanations. "Never assume theft" appears three
  times in the specification; here it is one constructor.
- `Total::describe()` renders a mixed figure's split in the same method that
  computes the value, so there is no code path that prints the number alone.
- `AiBoundary` enumerates all eleven forbidden AI actions and the regression
  test loops over the enum: a new prohibition cannot be added without being
  enforced, or deleted without a test failing.
- `PriceReview` has no method that returns a new price; `AccountsComparison` has
  no method that writes. Both absences are asserted by tests.

### Added
- `shop-intelligence/src/` — the framework-free analysis core, `Shop\`
  namespace: truth primitives, allocations and match history, platform payout
  reconciliation, recipes and stock reconciliation, cost entries and recurring
  costs, management profit, channel and product margin, price review, cash
  ledger, monitoring, loss ranking, monthly report and text renderer.
- 27 numbered regression tests mapped in `docs/REGRESSION_MAP.md`, plus an
  isolation suite.
- `bin/check-shop-isolation.sh` — 10 mechanical checks for §28.
- `bin/shop-demo` — a complete worked month with no database and no framework.
- `docs/BANKING_UX.md` — what "work like a banking app" means as a design.
- `docs/FABLE_BRIEFING_2026-09-08.md` — the handover.

### Verified
- 60 tests, 469 assertions, 0 failures, 0 errors, 0 skipped on PHP 8.4.19,
  with `failOnWarning` and `failOnRisky` both on.
- Isolation guard: 10 checks, 0 violations.

### Known — say it before it is discovered
No user interface, no database, no migrations, no authentication, no importers,
no month-close storage, not deployed. The analysis core is real; everything
around it is not built. The 27 regression tests were derived from the
specification body rather than transcribed from its §29 list, and that
reconciliation is the first item in the briefing.

## 2026-09-07 (sixth) — final release gate

Recorded as `docs/RELEASE_RECORD_2026-09-07.md` with measured numbers.

### The blocker the gate found
The Liberu ERP could not migrate onto MySQL or MariaDB at all: 112 index and
foreign-key names derived by Laravel exceed the 64-character identifier limit,
so `migrate` failed partway through and left a half-created schema. The
one-command installer provisions MariaDB, so the deploy command previously given
would have failed. Found only because this gate insisted on a real database
instead of the SQLite used in development.

Fixed by `bin/patch-foundation-index-names.sh` — deterministic, idempotent,
lossless, with a `--check` mode wired into CI and the installer. Upstream bug;
must be re-applied after every foundation upgrade, which the installer does.
After the fix all 295 migrations apply.

### Changed
- Installer now writes `POLAND_REQUIRE_OFFICIAL_RATES=true`. The previous
  `false` made the app immediately usable and was the wrong default: the safe
  value must be the default and relaxing it a conscious act.
- Caveat reason codes are now stable identifiers
  (`OFFICIAL_RATES_NOT_VERIFIED`, `KSEF_TRANSPORT_UNAVAILABLE`,
  `BANK_STATEMENT_MISSING`, `POSSIBLE_DUPLICATE_TRANSACTION`, …) and each names
  who resolves it — taxpayer, operator or accountant.
- The restore drill's scratch database needed a grant the least-privilege
  accounting user did not have. Rather than widening access, the installer now
  grants a dedicated `<db>_drill` namespace; the drill fails loudly without it.

### Added
- `bin/release-gate.sh` — all fourteen checks, non-zero on failure, `REQUIRES
  HUMAN` for the real-data pilot.
- `ReleaseGateTest` — 20 tests, one per numbered gate.
- Drill extended to 17 tables plus content-level comparison: KSeF XML compared
  by SHA-256 on both sides, report checksums, reconciliation decisions and
  settlement amounts.

### Measured
309 tests, 874 assertions, 0 failures, 0 errors, 0 skipped, on PHP 8.4.19 and
8.5.0. Laravel 13.29.0, MariaDB 10.11.14, 295 migrations. Backup restored and
verified: 27 checks.

### Milestones
LIVE APPLICATION reached. PRODUCTION ACCOUNTING not reached — needs rate
verification and the real-data pilot. AUTOMATED FILING not reached, deliberately.

## 2026-09-07 (fifth) — production audit response

Recorded in full as `docs/PRODUCTION_AUDIT_2026-09-07.md`. Four real gaps closed;
everything the audit told us to keep is now pinned by a test that fails if it is
weakened.

### Schema
| Migration | Change |
|---|---|
| `2026_09_07_001200` | `pl_bank_statements`: `completeness`, `covered_range`, `has_balances` |

### Gaps closed
- **§13** — `BLOCKED` and `FAILED` added as distinct certainty states, ranked
  above NOT ENOUGH DATA. Unverified rates and an unavailable KSeF reclassified
  from NOT ENOUGH DATA to BLOCKED: no upload fixes them.
- **§10** — `vat_surplus`, `payment_deadline` and `due_date` were NOT protected.
  A surplus read off a letter would have created a refund entitlement; a misread
  digit would have become the date somebody pays by. Added, along with six more
  and an explicit `EVIDENCE_FIELDS` list.
- **§16** — statement completeness was unknown, so "3 transactions imported"
  silently read as "all of August". COMPLETE / PARTIAL / UNKNOWN / OUTSIDE_PERIOD.
- **§20** — nothing stopped production selecting a fake transport. `TransportGate`
  now throws; a silent fallback would turn "no connection" into "no invoices".
- **§22** — dashboard integration panel: NOT CONNECTED / NOT AVAILABLE /
  DISABLED / NOT VERIFIED, each with what it means and the next step.

### Added
- `MISSING_FIELD` marker (§3) so an absent net amount can never render as blank
  or 0,00; unknown XML elements recorded rather than discarded.
- `SyncCursor` — the advance rule extracted so it is testable without a database.
- `TransactionLifecycle` — both documented paths as states; nothing is deleted.
- Four regression suites: `CredentialSecurityTest` (11), `EngineBoundaryTest`
  (46), `ValueComparisonTest` (19), `IntegrationStatusTest` (12),
  `SyncSafetyTest` (13).

### Verified
289 tests, 790 assertions, green on PHP 8.2-8.5. Full stack re-run on 8.5 with
296 migrations; month close now correctly reports BLOCKED rather than NOT
ENOUGH DATA.

## 2026-09-07 (fourth) — automation: KSeF, bank, government, reconciliation

### Schema
| Migration | Tables |
|---|---|
| `2026_09_07_000800` | `pl_ksef_credentials` (encrypted token), `pl_ksef_sync_state`, `pl_ksef_documents` |
| `2026_09_07_000900` | `pl_bank_statements`, `pl_bank_transactions`, `pl_transaction_classifications` |
| `2026_09_07_001000` | `pl_government_documents` |
| `2026_09_07_001100` | cross-format duplicate flagging on `pl_bank_transactions` |

All additive. `pl_ksef_documents.original_xml` and `ksef_number` are immutable
at the model level.

### Added
- **KSeF InvoiceRead pipeline**: namespace-agnostic FA parser (reads FA(1)/(2)/(3)
  and an unreleased schema version), dedup by unique index, incremental cursor
  that only advances on a complete run, immutable original XML, and REQUIRES
  REVIEW propagation that never rewrites a finished report.
- **Encrypted credentials**, InvoiceRead-only scope enforced at the enum, the
  model and the token accessor. The user's KSeF password is never stored.
- **Bank import**: CSV (bank-dialect detection, CP1250/ISO-8859-2), MT940 and
  camt.053, all validated against the statement's own opening/closing balances.
  PDF refuses with a route forward.
- **Cross-format duplicate detection** — the same payment from MT940 and camt is
  flagged and excluded rather than doubling costs or being silently dropped.
- **Deterministic matcher**: MATCHED needs amount-to-the-grosz plus a reference;
  amount alone never auto-books; one document cannot be claimed twice.
- **Government inbox** with five action classes, deadline extraction that
  refuses to invent a date from "within 14 days", and OCR as a refusing port.
- **AI boundary**: `RESERVED_FOR_ENGINE` fields throw; engine-vs-document
  disagreement escalates to MANUAL REVIEW and favours neither side.
- **Certainty states** (CALCULATED / VERIFIED / REQUIRES REVIEW / NOT ENOUGH
  DATA) combined by worst-case, with the specified messages and a remedy on
  every caveat.
- **Month close**: ten steps, each reporting what it could not do; a failing
  step downgrades certainty rather than aborting.

### Fixed
Two bugs found by running the pipeline, both of which would have doubled a
month's costs:
- The same transaction imported from two formats did not dedup, because optional
  fields differ between formats.
- The duplicate query compared a decimal column as a string and a date column
  against a date-only value, so it silently never matched.

### Verified
- 182 tests, 543 assertions, green on PHP 8.2-8.5.
- Full pipeline executed on the ERP: token encrypted and redacted, InvoiceWrite
  refused, 2 invoices imported, correction flagged, duplicates detected, XML
  immutable, new invoice after a report raising REQUIRES REVIEW with the report
  unchanged, three statement formats imported and reconciled, month close
  reporting NOT ENOUGH DATA with four named caveats.

### Known
- No KSeF HTTP transport (the API is unreachable from the build environment).
- No OCR toolchain.
- Rate verification still blocked. Unchanged.

## 2026-09-07 (third) — pre-live: report, checklist, deployment

### Schema
| Migration | Change |
|---|---|
| `2026_09_07_000600` | `pl_payment_obligations` — the monthly payment checklist, unique per (profile, period, kind) |
| `2026_09_07_000700` | `pl_report_versions` — immutable numbered snapshots of every report ever generated |

Migration note: both additive. `pl_report_versions` refuses updates (except
close/reopen) and deletes at the model level.

### Added
- **Monthly accountant report** in eight sections — summary, ZUS, PIT, VAT,
  financial result, payment checklist, provenance, warnings — laid out for
  browser, print and PDF (`@page` A4, print stylesheet).
- **Dashboard rebuilt** to answer "what do I have to pay this month?" first.
- **Payment checklist** with three states. NOTHING TO PAY is distinct from a
  zero-amount UNPAID, and a VAT surplus is stored in its own column so it can
  never be rendered as an amount due. Payments record date, amount, reference
  and notes; a shortfall against what was owed stays visible.
- **Financial result** for the month and year to date, with the income figure
  labelled an upper bound whenever no cost register exists.
- **Month close and report versioning.** Every generation writes an immutable
  numbered version with a checksum. Recomputing writes version n+1 and never
  touches n. Closing freezes the month; reopening needs a reason and is audited.
- **Cost and input-VAT entry**, so skala/liniowy can stop being an upper bound.
- 2024/2025 health contribution year, without which January 2025 could not be
  settled at all. All 24 months of 2025–2026 are now settleable.
- `bin/deploy-contabo.sh` — one-command production install.
- `bin/data-safety-drill.sh` — backup, restore, compare row counts, report
  checksums and audit events; refuses to pass on any mismatch.
- `bin/enable-email-verification.sh` — opt-in, idempotent, anchor-safe.
- `docs/RELEASE_CHECKLIST.md` — every gate with its current state.

### Verified
- 130 tests, 346 assertions, green on PHP 8.2–8.5.
- Full stack on PHP 8.5: 289 migrations, 9 routes, report generated at v1, ZUS
  paid, regenerated at v2 with the payment preserved, v1 proven immutable and
  undeletable, closed month refusing regeneration, reopen requiring a reason.
- All eight report sections and the NOT VERIFIED banner present in the rendered
  HTML.

### Known
- Rate verification against official sources is still blocked by network policy.
  Unchanged and still the one thing between this and real tax payment.

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
