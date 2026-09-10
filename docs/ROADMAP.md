# Roadmap

> **Direction change, 2026-09-10.** The owner's real workflow is now the
> binding design: `docs/ACCOUNTING_WORKFLOW_AND_DATA_MODEL.md`. Phases 2–5
> below stay as the reference for the tax engine, KSeF adapter, JPK and
> automation, but their sequencing is superseded by that document's stages
> B–F (review inbox and postings → real KSeF transport → Glovo importer →
> JPK_V7 preparation → trusted-supplier auto-approval). The daily-grain
> sales proposal is cancelled; sales stay monthly.

Phase 1 is built. Everything below it is specified and not built. Nothing in a
later phase is half-implemented, because a half-built KSeF client that looks
present is worse than none.

## Phase 1 — Foundation ✅ BUILT

- Liberu ERP installed and pinned (`bin/install-foundation.sh`)
- Polish locale, PLN, Warsaw timezone (`.env.example`)
- Poland module: profile, cash-register sales, purchase register, ledger
- VAT, ZUS and PIT engines with versioned 2025/2026 rate tables
- Monthly report: what to pay, by when, with the full derivation
- Dashboard, artisan commands, standalone CLI
- Append-only audit trail
- Own database, queue, storage, login; isolation guard
- 85 tests

Not yet done in Phase 1: customer/supplier records, invoice issuing and the
chart of accounts come from the Liberu foundation and have not been configured
for Polish defaults. See OPEN_ITEMS.

## Phase 2 — Poland tax engine completion

- **NBP exchange rates.** Fetch table A, cache it, and store the exact rate,
  table number and date used by each transaction. Never recalculate a historical
  transaction when a rate changes.
- Polish chart of accounts, VAT registers (sales and purchases) as ledger reads.
- KPiR / ewidencja przychodów as a proper book rather than a derived report.
- Multi-rate ryczałt from an invoice register rather than a single profile rate.

## Phase 3 — KSeF

Built as a configurable adapter, because the Ministry of Finance's endpoints,
authentication and schema versions have all changed before and will again.
Nothing about dates, URLs or schema versions may be hard-coded.

- FA(2)/FA(3) XML generation, validated against the official XSD at runtime
- Authentication per the current API requirements
- Submission, status polling, KSeF reference number storage
- **Idempotency**: an invoice cannot be submitted twice, enforced in the
  database, not in application flow
- Rejection handling and safe retries with backoff
- Incoming invoice download and supplier matching
- The original XML, the validation result, timestamps and errors all retained

Production credentials are not installed until the full suite passes against the
KSeF **test** environment.

## Phase 4 — JPK

- JPK_V7M / V7K from the VAT registers, with applicability driven by the
  taxpayer profile rather than assumed
- JPK_FA and JPK_KR where the taxpayer needs them
- XSD validation before any export is offered
- Every export records period, taxpayer, source records, generation timestamp,
  schema version, validation status and an audit entry

## Phase 5 — Automation

- Document inbox with the full status flow (NEW → PROCESSING → REVIEW →
  APPROVED → POSTED → SUBMITTED → REJECTED → ARCHIVED)
- Bank statement import (CSV and common Polish formats), matching, duplicate
  detection, reconciliation
- Recurring invoices, categorisation suggestions, deadline reminders
- Accountant export

Nothing uncertain posts to the ledger without review.

## Phase 6 — SignalMesh navigation

One link. `docs/SIGNALMESH_NAV_LINK.md` has the exact change and the
verification steps. Deliberately not applied yet.

## Definition of done

The project is ready for real accounting use when all seventeen conditions in
the specification hold. Currently satisfied: separation of accounting from
trading (1–4, 14, 16, 17), PIT/ZUS/VAT calculation and its tests (8, 9), the
audit trail (11), and environment separation (13). Outstanding: Polish invoicing
(5), KSeF (6), VAT/JPK generation (7), NBP recording (10), a tested restore on
the real server (12), and the navigation link (15).
