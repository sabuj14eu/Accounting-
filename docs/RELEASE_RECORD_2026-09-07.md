# Release record — 2026-09-07

Measured, not estimated. Every number here came from a command that ran.

Reproduce with:

```bash
PHP_BIN=/path/to/php8.5 bin/release-gate.sh /srv/accounting/foundation
```

---

## 1. Complete test suite

Run from a clean checkout, whole suite, not selected suites.

| | |
|---|---|
| **Total tests** | **309** |
| **Total assertions** | **874** |
| **Failures** | **0** |
| **Errors** | **0** |
| **Skipped** | **0** |
| **Incomplete / risky** | **0** |
| PHP (suite) | 8.4.19 and 8.5.0 — identical results |
| PHP (application) | **8.5.0** (built from `php-src` tag `php-8.5.0`) |
| Laravel | **13.29.0** |
| Database | **MariaDB 10.11.14-0ubuntu0.24.04.1** |
| Migrations applied | **295** |
| Commit | `87ff25c559cb09eababf95bb4303d42149967ca7` (this record adds one commit on top) |
| Branch | `claude/poland-accounting-app-pijnfm` |

`phpunit.xml` sets `failOnWarning="true"` and `failOnRisky="true"`, so a warning
or risky test is a failure rather than a note.

---

## 2. Production configuration — fails closed

Verified on the running application's `.env` **and** in the installer's
defaults, because a correctly configured host is worth nothing if the next
deploy starts unsafe.

| Setting | Required | Measured |
|---|---|---|
| `POLAND_REQUIRE_OFFICIAL_RATES` | `true` | **true** |
| `KSEF_TRANSPORT_ENABLED` | `false` | **false** |
| `KSEF_TRANSPORT` | `disabled` | **disabled** |
| `FilingChannel::automated()` | `false` for every channel | **false** — asserted by test |

**Changed for this gate:** the installer previously wrote
`POLAND_REQUIRE_OFFICIAL_RATES=false` so the app would be immediately usable.
That is the wrong default — the safe value must be the default, and relaxing it
must be a conscious act. It now writes `true`, and the installer prints how to
relax it and what that costs.

**No path selects a fake transport accidentally.** `TransportGate` throws when a
production environment is configured with `KSEF_TRANSPORT=fake`, so an
environment variable, a config file, a deployment default or a database value
cannot degrade production into a mock. There is no admin UI for it.

---

## 3. BLOCKED semantics — six tests

Each condition produces a distinct code, state and resolver. A test asserts all
four signatures differ, because if any two collapse the user is sent to the
wrong place.

| Condition | State | Code | Resolved by |
|---|---|---|---|
| Unverified rates | `BLOCKED` | `OFFICIAL_RATES_NOT_VERIFIED` | Accountant |
| KSeF unavailable | `BLOCKED` | `KSEF_TRANSPORT_UNAVAILABLE` | Operator |
| Missing bank statement | `NOT ENOUGH DATA` | `BANK_STATEMENT_MISSING` | Taxpayer |
| Ambiguous duplicate | `REQUIRES REVIEW` | `POSSIBLE_DUPLICATE_TRANSACTION` | Taxpayer |

Only the third is `isUserActionable()`. Uploading a bank statement does not
verify a rate table, and the status says so.

## 4. Engine boundary — 46 tests

One test per field in `RESERVED_FOR_ENGINE`, plus the named list: `zus_total`,
`pit_due`, `vat_due`, `vat_surplus`, `payment_deadline`, `due_date`, `tax_base`,
`contribution_base`, `rate`. A companion test asserts no field appears on both
the reserved and the evidence list.

`stated_amount` is permitted; `zus_total` is not. Disagreement between engine and
document yields `MANUAL REVIEW REQUIRED` with both figures and the difference
shown, in both directions.

## 5. Report immutability — verified end to end on MariaDB

1. August report **v1** generated.
2. New KSeF invoice imported for August.
3. **v1 unchanged** — the model refuses updates and deletes; checksum verified.
4. `report.requires_review` audit event recorded.
5. Regeneration produced **v2**; both versions retained.
6. The month reports `REQUIRES REVIEW` with `NEW_DOCUMENTS_AFTER_REPORT`.

Row counts after the drill: `pl_report_versions` = 2, both restored byte-identical.

## 6. Bank completeness — all four states, distinct

| Statement | State |
|---|---|
| 1–31 August, balances present | `COMPLETE` |
| 1–15 August | `PARTIAL` |
| no period stated | `UNKNOWN` |
| 1–30 June | `OUTSIDE_PERIOD` |

Asserted explicitly: **`UNKNOWN != COMPLETE`** and **`PARTIAL != COMPLETE`**.
`UNKNOWN` returns `complete => null` — neither, because a format that states no
period cannot prove it covered one.

## 7. KSeF failure semantics

Displayed when disconnected:

> **KSeF — NOT CONNECTED.** Transport HTTP do KSeF nie jest włączony. System NIE
> pobiera faktur zakupowych. To nie znaczy, że faktur nie ma — znaczy, że ich
> nie widzimy.

A test asserts the forbidden phrasings ("no invoices found", "brak faktur") do
not appear. The client itself throws rather than returning an empty page.

## 8. OCR failure semantics

An unreadable document is classified `UNKNOWN / MANUAL REVIEW`, never
`INFORMATION ONLY`, `textUnavailable = true`, `needsManualReview = true`, and the
original filename retained. `UnavailableTextExtractor` **throws** — a test
documents that empty text would otherwise classify as INFORMATION ONLY and a
demand for payment would sit until its deadline passed.

## 9. Security sweep

| Check | Result |
|---|---|
| Credential literals committed | **none** |
| Token reaching a logger | **none** |
| `reveal()` call sites in `src/` | **0** (no transport exists yet; the bound is ≤ 2) |
| `.env` gitignored | **yes** |
| KSeF password stored | **never** — token only, revocable independently |
| Token encrypted at rest | **yes** — verified as ciphertext in the MariaDB column |
| Token in `toArray()` / JSON / debug / `__toString` | **absent** — 11 regression tests |
| `InvoiceWrite` obtainable by editing a database row | **no** — refused at the accessor |

## 10. Backup and restore — performed for real

MariaDB dump taken, restored into a scratch database, compared. **27 checks, all
passed.**

Row counts identical across: `users`, `pl_tax_profiles`, `pl_sales_reports`,
`pl_sales_report_lines`, `pl_purchase_summaries`, `pl_ksef_credentials`,
`pl_ksef_documents`, `pl_ksef_sync_state`, `pl_bank_statements`,
`pl_bank_transactions`, `pl_transaction_classifications`,
`pl_government_documents`, `pl_settlements`, `pl_report_versions`,
`pl_prepared_documents`, `pl_payment_obligations`, `pl_audit_events`.

Content-level, not just counts:

- original KSeF XML byte-identical (SHA-256 compared, both sides)
- report versions identical, stored checksums verified
- reconciliation decisions and matched documents identical
- settlement amounts (ZUS / PIT / VAT / total) identical
- audit trail complete — 11 events
- no orphaned payment obligations

**Design conflict found and fixed:** the accounting database user is scoped to
its own schema, which is correct, and that prevented the drill creating its
scratch database. Granting a dedicated `<db>_drill` namespace keeps the
isolation and lets the restore actually be proven. Both the installer and the
drill were updated; the drill now fails loudly if the grant is missing rather
than being quietly skipped.

## 11. Historical reproducibility — 12 tests

January 2026 settles on the 2025/2026 contribution year (461,66), February on
2026/2027 (498,35). A 2025 month uses the 2025 social base (5 203,80 → 1 773,96),
never the 2026 one. Settling the same historical month twice yields identical
figures and identical rate sources. Every settlement stores the version
identifier of each table it used.

## 12. Real-data pilot — REQUIRES YOU

Not done, and not doable here: it needs one real bank statement, real invoices,
real monthly sales and an accountant's records to compare against. This is the
last gate before **PRODUCTION ACCOUNTING**, and the gate script reports it as
`REQUIRES HUMAN` rather than passing silently.

## 13. Honest UI — 12 tests

```
KSeF                        NOT CONNECTED
Import wyciągów bankowych   AVAILABLE
OCR / odczyt tekstu         NOT AVAILABLE
Stawki podatkowe            NOT VERIFIED
Wysyłka do organów          DISABLED
```

Rendered and asserted present in the live HTML. Every non-operational row states
what it means and the next step. No disabled dependency is shown green.

---

## The blocker this gate found

**The Liberu ERP could not migrate onto MySQL or MariaDB at all.**

Laravel derives index and foreign-key names from the table plus every column.
MySQL and MariaDB cap identifiers at 64 characters; **112 derived names at the
pinned commit exceed it**. `php artisan migrate` failed partway through, leaving
a half-created schema — and the one-command installer provisions MariaDB, so the
deploy command previously handed over **would have failed**.

Found only because this gate insisted on a real database rather than the SQLite
used for development.

Fixed by `bin/patch-foundation-index-names.sh`, which gives each affected index
an explicit deterministic short name. The rewrite is lossless — index names carry
no meaning beyond uniqueness within their table — and idempotent, so re-running
after an upgrade produces an identical schema. `--check` exits non-zero while any
name is still too long. The installer now runs it before migrating.

This is an upstream bug and should be reported there. Until it is fixed upstream,
the patch must be re-applied after every foundation upgrade; the installer does
that automatically.

After the fix: **295 of 295 migrations apply**, all 16 `pl_` tables created.

---

## Milestones

| Milestone | Status |
|---|---|
| **LIVE APPLICATION** — deployed and usable | **REACHED** |
| **PRODUCTION ACCOUNTING** — verified for real accounting use | **NOT REACHED** — needs official rate verification (§21) and the real-data pilot (§12) |
| **AUTOMATED FILING** — government submission operational | **NOT REACHED** — deliberately disabled; every channel throws |

These are three different things and this record does not conflate them.

## What ships enabled

Real login · real accounting data · real documents · real bank statement import
(CSV, MT940, camt.053) · monthly reports · payment checklist · audit trail ·
backup and restore.

## What ships disabled, and stays disabled

KSeF submission · JPK submission · government submission · automatic payments ·
AI override of engine figures. Every filing channel is bound to an adapter that
throws, and a test asserts `automated()` remains false for all of them.

The fail-closed behaviour is the feature. It is not removed because an
integration is unavailable.
