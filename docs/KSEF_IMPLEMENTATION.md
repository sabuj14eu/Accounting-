# KSeF implementation map

Written against KSeF API **2.7.1** (OpenAPI build `2.7.1-te`) and **FA(3)
1-0E**, pinned from `CIRFMF/ksef-docs` @ `93b843d5` (2026-08-26). See
`modules/poland/resources/ksef/PINNED.md`.

## Layering

```
Laravel layer   src/Laravel/…            controllers, Eloquent, services, commands, Filament hooks
────────────────────────────────────────────────────────────────────────────────
Framework-free  src/Ksef/…               everything below needs only PHP 8.2 + dom/openssl/curl
```

The framework-free layer is where the rules live and where the suite runs.
The Laravel layer is thin and is exercised by CI's `laravel-integration` job
and on the server, not by this repository's PHPUnit.

## Framework-free (`modules/poland/src/Ksef`)

| Path | Responsibility |
|---|---|
| `Secret.php` | a credential value that redacts itself in every rendering; `reveal()` is the single, greppable accessor |
| `KsefEnvironment.php`, `TransportGate.php`, `KsefEndpoints.php` | test/demo/production; DISABLED·FAKE·TEST·DEMO·PRODUCTION; base URL resolution that refuses a production override |
| `KsefScope.php` | InvoiceRead + InvoiceWrite allowed (decision 2026-09-08); credential/introspection scopes refused by name |
| `KsefNumber.php` | 35-char KSeF number format + CRC-8 (poly 0x07) |
| `KsefRetryPolicy.php` | backoff, cap, Retry-After, never on unknown outcome |
| `Error/` | `KsefErrorCategory`, `KsefException` (with `outcomeKnown`), `Redactor` |
| `Http/` | `HttpClient` port, `HttpRequest`/`HttpResponse`, `HttpFailure` (with `requestWasSent`), `CurlHttpClient` |
| `Transport/` | `KsefTransport` (one method per endpoint), `RealKsefTransport`, `FakeKsefTransport` (tests), `DisabledKsefTransport`, DTOs per contract |
| `Crypto/` | `BigInt` (pure-PHP modpow), `RsaOaep` (SHA-256/MGF1), `KsefCryptography` (token encryption, session key wrap, AES-256-CBC, certificate selection) |
| `Auth/` | `KsefAuthenticator` (challenge → encrypt → init → poll → redeem; refresh), `AuthenticatedContext`, `JwtClaims` (display only) |
| `Fa3/` | `Fa3Invoice` and parts, `Fa3VatRate` (the XSD's `TStawkaPodatku`), `Fa3XmlGenerator` (deterministic), `Fa3SchemaValidator` (offline XSD, XXE-safe), `Fa3SemanticChecks`, `ErpInvoiceSnapshot` + `ErpInvoiceMapper` (refuses, never fills), `Fa3Schema` (pinned constants) |
| `Outgoing/` | `KsefSubmissionState` (closed state machine), `SubmissionIdentity`, `KsefInvoiceSender` (session, send with safe recovery, status, UPO, close), `SubmissionStatus` (code → state) |
| `Incoming/` | `IncomingSyncEngine`, `SyncStore` port, `SyncCursorState`, `IncomingInvoiceRecord`, `IncomingSyncReport` |
| `Upo/` | `UpoParser`, `UpoDocument` (verbatim + fields, schema-checked) |
| `Health/` | `KsefHealth`, `HealthCheck` |
| `Audit/` | `KsefAuditSink` port, action vocabulary, in-memory and null sinks |
| `Parsing/` (existing) | `FaInvoiceParser` for incoming bodies |

## Laravel layer (`modules/poland/src/Laravel`)

| Path | Responsibility |
|---|---|
| `database/migrations/2026_09_08_000100_…` | tables below |
| `Models/Ksef*.php` | Eloquent; documents and status events append-only at the model level; tokens encrypted casts |
| `Support/Ksef/KsefTransportFactory.php` | one transport per deployment through the gate |
| `Support/Ksef/KsefSettingsService.php` | the five wizard steps; token stored encrypted with a fingerprint |
| `Support/Ksef/KsefTokenService.php` | valid access token: reuse → refresh → re-authenticate; sessions persisted |
| `Support/Ksef/KsefConnectionService.php` | "Test connection" (real auth, no invoice), health facts, counts |
| `Support/Ksef/KsefSubmissionService.php` | prepare/approve/send/poll/UPO/manual review with row locks and append-only events |
| `Support/Ksef/ErpInvoiceSnapshotBuilder.php` | reads `App\Models\Invoice`/items/customer + `pl_ksef_customer_identifiers` |
| `Support/Ksef/EloquentSyncStore.php` | page + cursor in one `DB::transaction` |
| `Support/KsefIngestService.php` | incremental runs per subject type, `pl_ksef_sync_runs`, month-close compatibility |
| `Support/Ksef/LaravelKsefAuditSink.php`, `KsefErrorRecorder.php` | audit and error persistence, scrubbed |
| `Http/Controllers/KsefController.php`, `routes/web.php` | `/poland/ksef/*` |
| `resources/views/ksef/*.blade.php` | status, wizard, submissions, submission |
| `Filament/KsefStatusPage.php`, `KsefRenderHooks.php`, `overlay/` | panel page + hooks on Sales Orders and the invoice editor |
| `Console/Ksef*Command.php` | `poland:ksef-test`, `-sync`, `-poll`, `-health`; scheduler wiring in the provider |

## Database

| Table | Holds | Constraints that matter |
|---|---|---|
| `pl_ksef_credentials` (extended) | environment, NIP, encrypted token + fingerprint, declared/observed permissions, seller address, invoice defaults, wizard step, last test/auth/sync facts, production enablement record | unique (profile, environment) |
| `pl_ksef_auth_sessions` | encrypted access/refresh tokens with expiry, method, permissions, status | unique reference_number |
| `pl_ksef_invoice_documents` | outgoing FA(3) XML and UPO XML verbatim, hashes, schema, validation | immutable at model level |
| `pl_ksef_submissions` | local state + KSeF status, references, KSeF number, dates, reasons, attempts | **unique active_key**, unique invoice_reference, unique ksef_number |
| `pl_ksef_status_events` | from/to/code/description/source/reference/actor/time | append-only |
| `pl_ksef_sync_cursors` | HWM, window, page offset, in-progress | unique (profile, environment, subject) |
| `pl_ksef_sync_runs` | per run: window, pages, counts, report, stop reason | |
| `pl_ksef_errors` | classified errors | |
| `pl_ksef_customer_identifiers` | buyer NIP/VAT-UE/other/none confirmed by a person | unique (profile, customer) |
| `pl_ksef_documents` (extended) | incoming invoices: + subject type, run, hash, dates, `xml_missing_reason`; XML nullable | unique (profile, ksef_number) |

## Tests (`modules/poland/tests/Unit/Ksef`)

All 20 classes listed in the specification (§44) plus `KsefCryptographyTest`,
framework-free, run by `vendor/bin/phpunit`. Live TEST/DEMO runs are not part
of the suite and are tracked in `docs/KSEF_PRODUCTION_GATE.md`.

## Known limitations

- Invoice types: VAT and KOR. ZAL/ROZ/UPR, attachments, batch sessions,
  offline modes, Peppol, third subjects and authorised subjects are out of
  scope and refused at construction.
- Correction data is accepted by the mapper but the ERP has no correction
  document; a correction must be prepared from a snapshot by hand until the
  ERP grows one.
- The ERP has no customer NIP; buyers are confirmed once per customer in the
  KSeF screen.
- Currency is `config('poland.currency')` (PLN); the ERP invoice has no
  currency column.
- DEMO/PRODUCTION base URLs are derived from the documented hosts by the TEST
  pattern; confirm on first contact.
- The Laravel layer has not been executed in this build environment (PHP 8.4,
  no foundation).
