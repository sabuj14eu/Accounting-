# KSeF 2.0 / FA(3) — what the integration is and how it behaves

The accounting application talks to the Krajowy System e-Faktur through the
**official KSeF 2.0 machine-to-machine API** (version 2.7.1, pinned in
`modules/poland/resources/ksef/PINNED.md`), issuing invoices in the **FA(3)**
structure and retrieving incoming ones. It never touches the taxpayer web
application (`ap.ksef.mf.gov.pl/web`) except to send a person there to grant
permissions and generate a token.

The deterministic tax engine remains the source of truth for every figure.
KSeF is an external government system whose state is recorded beside the
accounting record, never instead of it.

**Status (exact vocabulary, see `docs/KSEF_PRODUCTION_GATE.md`):**
CODE-COMPLETE · AUTOMATED-TESTED · LIVE-TEST-VERIFIED: NOT YET ·
DEMO-VERIFIED: NOT YET · PRODUCTION: OFF. Accepted as CODE-COMPLETE /
PRE-PRODUCTION, not as a verified live integration. The fourteen-gate
verification sequence that changes those words runs on the real PHP 8.5
deployment and is written in the gate document.

## Modes and environments

| Mode | Meaning |
|---|---|
| DISABLED | Transport off. Every KSeF call refuses. Nothing is sent or fetched. |
| FAKE | The scripted `FakeKsefTransport`. Tests only. Refused at boot in production. |
| TEST | Real API, `https://api-test.ksef.mf.gov.pl/v2`. No legal effect. Test NIPs only. |
| DEMO | Real API, `https://api-demo.ksef.mf.gov.pl/v2`. No legal effect. Production-like limits. |
| PRODUCTION | Real API, `https://api.ksef.mf.gov.pl/v2`. **Legal effect.** Gated (docs/KSEF_PRODUCTION_GATE.md). |

Set by `KSEF_ENVIRONMENT`, `KSEF_TRANSPORT`, `KSEF_TRANSPORT_ENABLED`. One
environment per deployment; credentials belong to an environment and are never
used against another. The three URLs are pinned verbatim from official
material (`modules/poland/resources/ksef/PINNED.md`, "Environments") and
`KsefEnvironmentPinTest` fails if code or configuration drifts from the
pinned files.

## Authentication

KSeF-token authentication, exactly as the pinned guide describes:

1. `GET /security/public-key-certificates` → the certificate with usage
   `KsefTokenEncryption`, valid now, latest `validFrom`.
2. `POST /auth/challenge` → `challenge`, `timestampMs`.
3. `{token}|{timestampMs}` encrypted with **RSA-OAEP SHA-256 / MGF1-SHA-256**
   (implemented in pure PHP because PHP's OpenSSL binding only offers SHA-1
   OAEP; verified round-trip against the `openssl` CLI in the suite).
4. `POST /auth/ksef-token` with `publicKeyId` → `referenceNumber` + temporary
   `authenticationToken`.
5. `GET /auth/{referenceNumber}` until 200 (100 = still in progress;
   415 = no permission → AUTHORIZATION_ERROR; 450 = bad token →
   AUTHENTICATION_ERROR; 425 revoked; 460 certificate).
6. `POST /auth/token/redeem` (once) → access token (~minutes) + refresh token
   (≤ 7 days). Refreshed with `POST /auth/token/refresh`; re-authenticated only
   when the refresh token dies.

Tokens are `Secret` objects in memory (redacted in every rendering) and
encrypted columns at rest (`pl_ksef_auth_sessions`, `pl_ksef_credentials`).
The KSeF token is pasted once in the wizard and never displayed again; the
screen shows a 12-character fingerprint so a person can tell which token it is.

The permissions the token actually carries are read from the access token's
claims **for display only**; KSeF makes every authorization decision itself.

## Outgoing invoices

```
accounting invoice (ERP) ──▶ ErpInvoiceSnapshot ──▶ ErpInvoiceMapper ──▶ Fa3Invoice
   ──▶ Fa3SemanticChecks ──▶ Fa3XmlGenerator ──▶ Fa3SchemaValidator (official XSD)
   ──▶ DRAFT → VALIDATED | BLOCKED
   ──▶ (person) READY ──▶ (person clicks "Wyślij") SUBMITTED ──▶ PROCESSING
   ──▶ ACCEPTED (KSeF number, UPO) | REJECTED | MANUAL_REVIEW
```

- **Nothing is sent automatically.** A sales order is never an invoice; an
  invoice is never sent because it exists. Two explicit steps (approve, send).
- The mapper **refuses rather than fills in**: no confirmed buyer identifier,
  a line without a VAT rate for a VAT payer, an exempt line without its legal
  basis (P_19A), a NIP with a wrong checksum, a future issue date, VAT that
  does not match the rate → BLOCKED with every reason listed.
- The generated XML is stored **verbatim** with its SHA-256 before anything
  is sent (`pl_ksef_invoice_documents`, immutable). A regenerated document is a
  new row.
- Sending uses an interactive session: fresh AES-256 key + IV, key wrapped
  with RSA-OAEP for the `SymmetricKeyEncryption` certificate,
  `POST /sessions/online` (FA (3) 1-0E), `POST …/invoices` with the plain
  and encrypted hashes, then `GET /sessions/{ref}/invoices/{invRef}` until
  final, `GET …/upo`, `POST …/close`.
- **Submitted is not accepted.** 100/150 → PROCESSING. 200 → ACCEPTED only
  with a KSeF number that passes the CRC-8 check; 200 without one →
  MANUAL_REVIEW. 440 duplicate → MANUAL_REVIEW carrying the original KSeF
  number for a person to reconcile. 405/410/415/430/435/450 → REJECTED with
  the reason. Anything else → MANUAL_REVIEW.
- **A timeout is not a failure and not a success.** If the send request may
  have reached KSeF, the session's invoice list is searched for our document
  hash. Found → adopted. Not found → MANUAL_REVIEW. Never a second send.
- Idempotency is a **database unique index** (`active_key` = environment +
  seller NIP + type + number, NULL once rejected/cancelled), plus unique
  `invoice_reference` and `ksef_number`, plus a row lock on READY→SUBMITTED.
- The UPO is fetched from KSeF and stored verbatim, validated against
  `upo-v4-3.xsd`. It is never manufactured locally.

## Incoming invoices

Incremental retrieval per the pinned guide: `POST /invoices/query/metadata`
with `dateType=PermanentStorage`, ascending, `restrictToPermanentStorageHwmDate`,
`pageOffset`/`pageSize`, subject type Subject2 (we are the buyer) and Subject1
(we are the seller), then `GET /invoices/ksef/{ksefNumber}` per new invoice.

The cursor rule:

```
request page → validate → fetch bodies → persist ALL of the page + advanced cursor
   in ONE transaction → next page
any failure → nothing of that page persists → cursor stays → next run retries that page
```

The window's end is the server's completeness mark (HWM) returned on the first
page and pinned for every later page, so offsets keep meaning the same rows.
`isTruncated` opens a new window from the last record's storage date. Only
when the last page lands does `synced_through` move to the HWM.

An empty page is an honest fact and completes the run. A transport error is a
`KsefException` that ends the run as PARTIAL/FAILED — **it is never "0
invoices"**. A body KSeF refuses to serve is stored as metadata with
`xml_missing_reason` and `needs_review`, so the month is not silently short.

Incoming invoices never become booked entries by themselves; they enter the
existing reconciliation and REQUIRES REVIEW flow, and a new invoice for a month
whose report exists marks that month for review instead of rewriting it.

## Errors and retries

Categories: AUTHENTICATION_ERROR · AUTHORIZATION_ERROR · NETWORK_ERROR ·
TIMEOUT · RATE_LIMIT · SERVER_ERROR · VALIDATION_ERROR · BUSINESS_REJECTION ·
DUPLICATE · CURSOR_ERROR · MALFORMED_RESPONSE · INTEGRATION_DISABLED ·
UNKNOWN_KSEF_ERROR. Each carries operation, environment, HTTP status, KSeF
code, safe message, details, Retry-After, reference, timestamp, `retryable`
and `outcome_known`. Every one is stored in `pl_ksef_errors`.

`KsefRetryPolicy`: exponential backoff (2·2ⁿ s, capped), a hard attempt limit,
Retry-After honoured, and **never a retry when the outcome is unknown**.

Every request has an explicit connect timeout and total timeout; responses
are capped in size while streaming; redirects are not followed; TLS is
verified.

## Status page and health

Four separate questions: CONFIGURED · API_REACHABLE · AUTHENTICATED ·
SYNCHRONIZATION_HEALTHY. "CONNECTED" requires a successful authentication no
older than `KSEF_AUTH_FRESHNESS_HOURS` (24). Credentials on disk are "NOT
VERIFIED". A failed authentication is "NOT CONNECTED" whatever the host said.

`php artisan poland:ksef-test` (real test), `poland:ksef-health` (stored
facts), `poland:ksef-sync`, `poland:ksef-poll`.

## Where things live

Settings → Poland → KSeF: `/poland/ksef` (status), `/poland/ksef/konfiguracja`
(five steps), `/poland/ksef/faktury` (outgoing). In the Filament panel: "Tax &
Compliance → KSeF" (installed by the overlay), a KSeF banner above Sales
Orders, and a KSeF section at the end of the invoice editor.

## What stays disabled

Automatic VAT/JPK/PIT/ZUS filing and automatic payment. Every such channel is
still bound to an adapter that throws. KSeF integration is not government
filing.
