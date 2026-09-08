# KSeF production gate

## Current status (exact vocabulary — use these words and no others)

| Term | Meaning | Now |
|---|---|---|
| CODE-COMPLETE | The implementation exists. | **YES** (2026-09-08) |
| AUTOMATED-TESTED | The automated suite passes. | **YES** — 453 tests, 1993 assertions, fake transport |
| LIVE-TEST-VERIFIED | The real KSeF TEST environment succeeded (gates 1–12 below). | **NOT YET** |
| DEMO-VERIFIED | The real DEMO environment succeeded (gate 13). | **NOT YET** |
| PRODUCTION-VERIFIED | A controlled production pilot succeeded (gate 14). | **NOT YET** |
| PRODUCTION | `KSEF_TRANSPORT` on the production deployment. | **OFF** (`disabled`) |

The system is **not** "KSeF production-ready" while LIVE-TEST-VERIFIED is
NOT YET. It is accepted as CODE-COMPLETE / PRE-PRODUCTION. That is an honest
release state and it is the one this document reports until a row below
changes with a date, a person and a proof.

**Status of the production gate: NOT REACHED.** Production KSeF stays OFF.
This document is the only place that may say otherwise, and it may say so
only after every row below reads PASS with a date, a person, and where the
proof is.

Production is a deployment setting, not a screen. Nothing in the UI can turn it
on; the wizard's "enable" step refuses on production until a real connection
test passed, and the installer defaults every deployment to
`KSEF_ENVIRONMENT=test`, `KSEF_TRANSPORT=disabled`,
`KSEF_TRANSPORT_ENABLED=false`.

## Verification sequence — fourteen gates, in order, on the real PHP 8.5 deployment

Each gate is run on the deployed application, never on a substitute. A gate is
recorded here with date, person and proof (a log path, a KSeF reference
number, a screenshot location — never a credential). A gate that was not run
is NOT REACHED, never assumed.

| Gate | What must be shown | How | Rows below | Status |
|---|---|---|---|---|
| 1 Runtime | Boots on PHP 8.5; all migrations apply; the eight `pl_ksef_*` tables, their unique constraints and indexes exist; wizard, status page, Sales Orders KSeF block and invoice panel render; settings save; health check runs; scheduler/queue can poll. | `sudo bash bin/ksef-runtime-check.sh /srv/accounting/foundation` (mechanical rows) + the HUMAN rows it lists, done logged in. No framework-free test substitutes. | prerequisite for 4–20 | NOT REACHED |
| 2 Database | Real backup before migration; migration succeeds; rollback succeeds in a disposable copy; restore works; KSeF XML, SHA-256, submissions, status history, sync cursors and audit records survive restore. | `bin/deploy-contabo.sh` writes `pre-migrate-*.sql.gz` before migrating; `sudo bash bin/data-safety-drill.sh` (steps 3, 5, 11, 12). Rollback = `php artisan migrate:rollback --step=1` against the `_drill` copy only, **never** the production database. | 18 | NOT REACHED |
| 3 Official endpoints | API version, TEST / DEMO / PRODUCTION URLs, OpenAPI, FA(3) and UPO checksums are explicitly pinned from official material; no undocumented endpoint is used. | `modules/poland/resources/ksef/PINNED.md` + `SHA256SUMS`; `KsefEnvironmentPinTest`. The live half: the first real response from each host is recorded here. | 1 | pinned PASS 2026-09-08; live NOT REACHED |
| 4 Real TEST authentication | challenge → RSA-OAEP → `/auth/ksef-token` → polling → success → redeem → usable access token → refresh, with the **server's** responses. | wizard step 3 + `php artisan poland:ksef-test`; `pl_ksef_auth_sessions` row; no credential in any log. | 4, 14 | NOT REACHED |
| 5 Real TEST connection | "Testuj połączenie": authenticated, correct NIP, correct environment, permissions reported, health CONNECTED; a failure never reads "0 invoices". | status page; `poland:ksef-health --json` | 5, 15, 19 | NOT REACHED |
| 6 Real FA(3) invoice | accounting invoice → mapper → FA(3) → XSD → submission → polling → ACCEPTED → real KSeF number → UPO; stored hash equals submitted XML. | one controlled TEST invoice; `pl_ksef_submissions`, `pl_ksef_invoice_documents` | 2, 3, 6, 7, 16 | NOT REACHED |
| 7 Rejection | An intentionally invalid invoice: KSeF rejects, local REJECTED, raw status and reason kept, never marked accepted, no blind resubmit. | `pl_ksef_status_events` for that submission | 6, 11 | NOT REACHED |
| 8 Timeout / unknown | SUBMISSION → UNKNOWN → MANUAL_REVIEW; recovery finds the true KSeF state without a duplicate. | controlled timeout (`KSEF_REQUEST_TIMEOUT` low, or a firewall rule), then "Rozstrzygnij" | 11, 12 | NOT REACHED |
| 9 Duplicate | Same scenario twice: no duplicate invoice, no duplicate active submission, DB constraint holds, KSeF 440 recorded with the original reference. | `pl_ksef_submissions.active_key` unique; the 440 event | 12 | NOT REACHED |
| 10 Incoming | Page 1 → persist → commit → cursor advance; forced page-2 failure leaves the cursor at the last safe position; resume retrieves page 2 without loss or duplicate. | real TEST data with incoming invoices; `pl_ksef_sync_cursors`, `pl_ksef_sync_runs` | 8, 9, 10 | NOT REACHED |
| 11 XML / UPO | For a real accepted invoice: original XML, UPO, UPO valid against the pinned XSD, stored hash equal, references and timestamps verified, storage immutable. Nothing manufactured locally. | submission detail page; `pl_ksef_invoice_documents` | 16 | NOT REACHED |
| 12 Security | On the deployed app: tokens and private keys never displayed; bearer headers never logged; exceptions, audit records, browser responses and serialised models carry no credential; production cannot select the fake transport or an arbitrary base URL. | manual review of `storage/logs/laravel.log`, `pl_ksef_errors`, `pl_audit_events`, page source; `KsefProductionSafetyTest` | 17 | NOT REACHED |
| 13 DEMO | Only after 1–12 pass on TEST: official DEMO endpoint, authenticate, test connection, controlled invoice, status, confirmation, incoming sync. Results recorded. | `KSEF_ENVIRONMENT=demo`, a DEMO token | 20 | NOT REACHED |
| 14 Production | `KSEF_TRANSPORT=disabled` until every row is PASS; then a controlled pilot: **one** real invoice, full lifecycle, before any other. Never automatic submission of all invoices. | this document, then the activation section | 21, 22 | NOT REACHED |

## The gates

| # | Gate | Status | Proof |
|---|------|--------|-------|
| 1 | Official API specification pinned (version, OpenAPI, FA(3) XSD, UPO XSD, environments) | PASS 2026-09-08 | `modules/poland/resources/ksef/PINNED.md`, `SHA256SUMS`; `KsefFa3ValidationTest::test_the_pinned_xsd_still_matches_what_the_code_emits` |
| 2 | FA(3) XML generation implemented, deterministic | PASS 2026-09-08 (unit) | `KsefInvoiceMapperTest` |
| 3 | Official XSD validation implemented, offline, XXE-safe | PASS 2026-09-08 (unit) | `KsefFa3ValidationTest` — the Ministry's own sample validates; ours validates; DOCTYPE/PI/BOM refused |
| 4 | Authentication tested against the real TEST API | **NOT REACHED** | needs a TEST token from https://ap-test.ksef.mf.gov.pl/web/ and network access to `api-test.ksef.mf.gov.pl` (refused from the build environment) |
| 5 | Authorization tested (token without InvoiceWrite → AUTHORIZATION_ERROR, no send) | unit PASS; live **NOT REACHED** | `KsefAuthorizationTest` |
| 6 | Invoice submission tested (TEST) | unit PASS; live **NOT REACHED** | `KsefSubmissionTest` |
| 7 | Status polling tested | unit PASS; live **NOT REACHED** | `KsefStatusTest`, `KsefSubmissionTest` |
| 8 | Incoming invoice retrieval tested | unit PASS; live **NOT REACHED** | `KsefIncomingInvoiceTest` |
| 9 | Pagination tested (first/middle/last/empty/duplicate/truncated/invalid) | unit PASS; live **NOT REACHED** | `KsefPaginationTest` |
| 10 | Cursor recovery tested (page 2 fails → page 1 stays, cursor on page 2, next run retries page 2) | unit PASS | `KsefCursorSafetyTest` |
| 11 | Retry behaviour tested (backoff, cap, Retry-After, never on unknown outcome) | unit PASS | `KsefRetryTest` |
| 12 | Duplicate delivery tested (one identity, KSeF 440 → manual review) | unit PASS; live **NOT REACHED** | `KsefIdempotencyTest` |
| 13 | Malformed response tested | unit PASS | `KsefMalformedResponseTest` |
| 14 | Credential expiration tested (expired refresh → re-auth; 450 → new token needed) | unit PASS; live **NOT REACHED** | `KsefAuthenticationTest` |
| 15 | Permission failure tested | unit PASS; live **NOT REACHED** | `KsefAuthorizationTest`, `KsefAuthenticationTest` (415) |
| 16 | UPO retrieval tested | unit PASS (official samples); live **NOT REACHED** | `KsefUpoTest` |
| 17 | Audit trail verified (every action, no secret) | unit PASS | `KsefAuditTest`, `KsefCredentialSecurityTest` |
| 18 | Backup/restore verified with KSeF tables | **NOT REACHED** | run `sudo bash bin/data-safety-drill.sh` on the server after migrating; steps 3 (row counts for all eight `pl_ksef_*` tables), 11 (FA(3)/UPO XML byte-identical, SHA-256 equal to content) and 12 (submissions, status history, cursors) must PASS |
| 19 | TEST environment green (all of 4–16 live) | **NOT REACHED** | `php artisan poland:ksef-test` → CONNECTED; one invoice ACCEPTED with UPO; one sync completed |
| 20 | DEMO environment green | **NOT REACHED** | same as 19 with `KSEF_ENVIRONMENT=demo` |
| 21 | Production credentials reviewed (token with exactly InvoiceRead + InvoiceWrite, generated in https://ap.ksef.mf.gov.pl/web/, owner known, expiry known) | **NOT REACHED** | recorded here with the token's fingerprint (never its value) |
| 22 | Production pilot approved by the taxpayer, in writing | **NOT REACHED** | recorded here |

The Laravel layer (migration, services, controller, Filament hooks) has been
syntax-checked but **not executed** in this build environment (PHP 8.4, no
foundation); the CI job `laravel-integration` (which now runs
`bin/ksef-runtime-check.sh`) and the server's first migration are where it
runs. That is Gate 1, not a footnote.

## Activation — only after all 22 rows read PASS

```env
KSEF_ENVIRONMENT=production
KSEF_TRANSPORT=real
KSEF_TRANSPORT_ENABLED=true
```

Then, in order:

1. `php artisan config:clear && php artisan migrate --force`
2. Settings → Poland → KSeF → Konfiguracja: step 2 (seller address), step 3
   (paste the PRODUCTION token — it is a different token from TEST/DEMO),
   step 4 (exemption basis if VAT-exempt), step 5 **Testuj połączenie** →
   must read CONNECTED with `InvoiceRead, InvoiceWrite` observed.
3. Step 5: tick "Włącz integrację". The audit trail records who enabled
   production and when (`pl_ksef_credentials.production_enabled_by/at`).
4. Send **one** invoice, by hand, and wait for ACCEPTED + UPO before any other.
5. Run `php artisan poland:ksef-sync --subject=Subject2` once and compare
   what arrived against what the taxpayer knows they bought.

What activation does NOT change: no invoice is ever sent automatically; no
JPK, PIT, ZUS or any government filing is sent (those channels remain bound
to `UnconfiguredSubmitter`, which throws).

## Rollback

Any step, any time:

```env
KSEF_TRANSPORT_ENABLED=false
KSEF_TRANSPORT=disabled
```

`php artisan config:clear`. Every screen returns to NOT CONNECTED; the
scheduler stops polling; nothing is deleted. Submissions already ACCEPTED keep
their KSeF numbers and UPOs — they are facts about KSeF, not about this
configuration. Submissions left PROCESSING are polled again when the transport
is re-enabled. A token can be revoked in the taxpayer application at any
time; the next authentication then fails with 450 and the status page says so.

## What a green unit suite does not prove

A passing unit test is not proof of a live government integration. The fake
transport simulates KSeF's documented behaviour; it cannot simulate the
undocumented parts, the rate limits under load, certificate rotation on a real
date, or whether the pinned DEMO and production hosts answer as documented
(Gate 3, live half).
