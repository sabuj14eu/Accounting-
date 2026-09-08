# KSeF production gate

**Status: NOT REACHED.** Production KSeF stays OFF. This document is the only
place that may say otherwise, and it may say so only after every row below
reads PASS with a date, a person, and where the proof is.

Production is a deployment setting, not a screen. Nothing in the UI can turn it
on; the wizard's "enable" step refuses on production until a real connection
test passed, and the installer defaults every deployment to
`KSEF_ENVIRONMENT=test`, `KSEF_TRANSPORT=disabled`,
`KSEF_TRANSPORT_ENABLED=false`.

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
| 18 | Backup/restore verified with KSeF tables | **NOT REACHED** | run `bin/data-safety-drill.sh` on the server after migrating; the drill must list `pl_ksef_submissions`, `pl_ksef_invoice_documents`, `pl_ksef_status_events`, `pl_ksef_sync_cursors`, `pl_ksef_auth_sessions` |
| 19 | TEST environment green (all of 4–16 live) | **NOT REACHED** | `php artisan poland:ksef-test` → CONNECTED; one invoice ACCEPTED with UPO; one sync completed |
| 20 | DEMO environment green | **NOT REACHED** | same as 19 with `KSEF_ENVIRONMENT=demo` |
| 21 | Production credentials reviewed (token with exactly InvoiceRead + InvoiceWrite, generated in https://ap.ksef.mf.gov.pl/web/, owner known, expiry known) | **NOT REACHED** | recorded here with the token's fingerprint (never its value) |
| 22 | Production pilot approved by the taxpayer, in writing | **NOT REACHED** | recorded here |

The Laravel layer (migration, services, controller, Filament hooks) has been
syntax-checked but **not executed** in this build environment (PHP 8.4, no
foundation); the CI job `laravel-integration` and the server's first migration
are where it runs. That is a gate 19 prerequisite, not a footnote.

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
date, or the DEMO base URL (derived from the documented host by the TEST
pattern and marked "confirm on first contact" in PINNED.md).
