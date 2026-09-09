# Data privacy and external connections

**Audited:** 2026-09-09, against the source in this repository. No code was
changed. Scope: `modules/poland/` (the accounting engine and Laravel layer),
`shop-intelligence/`, `config/`, `deploy/`, `bin/`, `.env.example`.

**The question this document answers:** *does any accounting data leave the
server, and if so, where to?*

**The answer:** **no data leaves the server today, and no code in this
repository is capable of sending any.** That is a stronger statement than a
policy, and it is stated below with the evidence for it — and with the two
places where it stops being true if somebody enables them.

---

## 1. The scan, and what it found

Every primitive PHP and Laravel offer for outbound communication was searched
for across `modules/poland/src` and `shop-intelligence/src`:

```
Http::            GuzzleHttp        curl_*
file_get_contents('http…')          fopen('http…')
fsockopen         stream_socket_client
Mail::            Notification::    SoapClient
dns_get_record
```

**Result: zero matches. Not one.**

A second scan looked for any URL literal anywhere in application source:

```
grep -rnoE "https?://…" modules/poland/src shop-intelligence/src
```

**Result: zero matches.** There is not a single hostname in the application's
own code.

### Why that is credible rather than lucky

`modules/poland/composer.json` and `shop-intelligence/composer.json` both
declare exactly one runtime requirement:

```json
"require": { "php": "^8.2" }
```

**No HTTP client is installed.** No Guzzle, no Symfony HttpClient, no SDK for
any vendor. The code could not make a network call without a new dependency
being added first — which is a visible, reviewable change, not an accident.

---

## 2. Every external destination the repository knows about

Nine hostnames appear anywhere outside `vendor/` and `.git/`. Each is
classified by whether the *running application* can reach it.

| Destination | Where it appears | Reached at runtime? |
|---|---|---|
| `github.com`, `api.github.com`, `raw.githubusercontent.com`, `packagist.org`, `getcomposer.org`, `packages.sury.org` | `bin/deploy-contabo.sh`, `bin/install-foundation.sh`, `.github/workflows/`, funding metadata | **No.** Install and CI only. Contacted by a human running a deploy or by GitHub Actions — never by the application serving a request. |
| `isap.sejm.gov.pl`, `www.zus.pl`, `stat.gov.pl`, `www.pit.pl`, and four Polish accounting-press sites | `modules/poland/config/rates/*.php` — the `source` field of each rate version; `docs/RATE_VERIFICATION.md` | **No.** These are **citations**, not endpoints. They record where a human must go to verify a figure. `RateRepository` reads the local PHP file and never dereferences the string. |
| `crd.gov.pl` | KSeF FA invoice XML fixtures and `FaInvoiceParser` | **No.** This is an **XML namespace identifier**, which happens to look like a URL. XML namespaces are never fetched. |
| `api.nbp.pl` | `.env.example` (`NBP_BASE_URL`) | **No.** A placeholder for a Phase 2 exchange-rate adapter **that does not exist**. `ExchangeRateProvider` is bound to `UnavailableExchangeRateProvider`, which refuses — because a missing rate is not 1.0 and is not yesterday's rate. |
| `account.signalmesh.dev`, `shop.signalmesh.dev` | nginx vhosts, `APP_URL` | **Inbound only.** These are this server's own names. |
| `ksef.mf.gov.pl` / `ksef-test.mf.gov.pl` | Named in docs; **`KSEF_BASE_URL` ships empty** | **No.** No HTTP transport exists. `KsefClient` is bound to `UnconfiguredKsefClient`, which throws. |

### Mail

`.env.example` ships:

```
MAIL_MAILER=log
```

Mail is written to a **local log file**. No SMTP host, no port, no credential,
no API key for any mail service. Nothing is emailed to anybody — not the
taxpayer, not an accountant, not a developer.

### Telemetry, analytics, advertising, crash reporting

**None.** No analytics script in any of the three Blade templates, no tracking
pixel, no error-reporting SDK, no product telemetry, no CDN reference. The
templates use inline `<style>` and system fonts — they do not even load a
webfont.

### AI / LLM services

**None, in either application.**

- The accounting module contains no AI code path whatsoever.
- `shop-intelligence/` models AI as a **boundary that refuses**, not as an
  integration. `Shop\Ai\AiAction` enumerates seven permitted actions
  (read, extract, classify, suggest a match, identify an anomaly, explain,
  summarise) and **eleven forbidden ones** — including
  `SUBMIT_TO_GOVERNMENT`, `ACCESS_KSEF_CREDENTIALS`, `CALCULATE_OFFICIAL_TAX`
  and `CHANGE_ACCOUNTING_RECORD`. Every forbidden case exists precisely so that
  asking for it throws, and every one is covered by
  `shop-intelligence/tests/AiBoundaryTest.php`.
- **No AI provider is wired up at all** — there is no Anthropic, OpenAI,
  Google or Microsoft client, no API key field, and no dependency that could
  reach one. The boundary describes what an AI *would* be allowed to do if one
  were ever connected. Today the answer to "what does the AI see?" is
  **nothing, because there is no AI**.

---

## 3. Where accounting data actually lives

```
        Data you enter or import
                  │
                  ▼
    ┌─────────────────────────────────┐
    │  Your server (Contabo)          │
    │                                 │
    │  MySQL/MariaDB  database        │
    │    pl_tax_profiles              │
    │    pl_sales_reports (+ lines)   │
    │    pl_purchase_summaries        │
    │    pl_settlements               │
    │    pl_report_versions           │
    │    pl_payment_obligations       │
    │    pl_ksef_credentials  (token  │
    │                    encrypted)   │
    │    pl_ksef_documents            │
    │    pl_ksef_sync_state           │
    │    pl_bank_statements           │
    │    pl_bank_transactions         │
    │    pl_transaction_classifications│
    │    pl_government_documents      │
    │    pl_prepared_documents        │
    │    pl_audit_events  (append-only)│
    │                                 │
    │  Redis  — session, cache, queue │
    │           (DB index 3 and 4)    │
    │  Filesystem — government docs   │
    │           (stored_path)         │
    └─────────────────────────────────┘
                  │
                  ✗  no outbound path exists
```

Note the database engine: `.env.example` configures **MySQL/MariaDB**
(`DB_CONNECTION=mysql`), not PostgreSQL.

`shop-intelligence/` declares a **separate database of its own**
(`DB_DATABASE=shop_intelligence`) and currently has **no migrations**, so
nothing persists there yet. Its `bin/check-shop-isolation.sh` proves the
separation mechanically — 10 checks, 0 violations at audit time.

### Isolation from the trading platform

Separate database, separate database user, separate Redis index (3 and 4),
separate session store, separate PHP-FPM pool, separate nginx server block,
separate document root. `bin/check-isolation.sh` fails the build if a trading
dependency, host or credential appears, and it runs before every deploy. It
passes as of this audit.

---

## 4. PRIVATE ACCOUNTING MODE — the honest status

You asked for this as a permanent security principle rather than a promise in
documentation. **Today it is the second thing: true in fact, but not enforced
by a mechanism.**

| | Status |
|---|---|
| No accounting data leaves the server | ✅ **True today**, and verified above |
| Nothing transmits automatically | ✅ **True today** — there is no transmitter |
| A named, testable rule that keeps it true | ❌ **Does not exist** |

The difference matters. `bin/check-isolation.sh` exists because "the accounting
app must not touch trading" was turned into a **script that fails the build**.
There is no equivalent for "accounting data must not leave the server". Nothing
would fail if somebody added Guzzle and posted a settlement to an external
service.

### What would make it a mechanism

Proposed, **not implemented** — this audit changes no code:

1. **A privacy guard script**, `bin/check-privacy.sh`, in the same style as the
   isolation guard, failing the build when application source contains any
   outbound primitive (`Http::`, Guzzle, `curl_`, `fopen`/`file_get_contents`
   on a URL, `Mail::`, `Notification::`) outside an explicitly declared and
   listed adapter.
2. **A declared destination allowlist** — a single file naming every host the
   application may ever contact, with why. Today it would contain exactly two
   candidates, both inactive: KSeF and NBP.
3. **Run it in CI and before deploy**, next to `check-isolation.sh`.
4. **A test asserting `MAIL_MAILER` is not a network mailer** in the shipped
   example configuration.
5. **A privacy row in the dashboard's integration panel** stating, from real
   configuration rather than from a promise, what is currently able to leave.

Recorded in `docs/OPEN_ITEMS.md`.

### The one deliberate exception, when you choose it

**KSeF is different, and there is no way around it.** If you enable KSeF, your
invoice data necessarily travels between your server and the Ministry of
Finance — that is what KSeF *is*. Two things keep it bounded:

- The scope is **`InvoiceRead` only**. This application can read your inbox and
  cannot issue or submit anything in your name.
- Nothing else is enabled by enabling KSeF. It is one destination, named, with
  its own credential, in its own table, per environment.

`NBP` would be the second, if an exchange-rate adapter is ever built. It would
send a date and a currency code — no accounting data.

---

## 5. What this audit did not cover

**The Liberu ERP foundation.** This repository is a *module*. The host
application is installed, not vendored — pinned by commit in
`bin/install-foundation.sh` and living at `/srv/accounting/foundation`. It is a
full Laravel application with its own dependency tree, and it was **not**
scanned here because its source is not in this repository.

That is where an outbound path would realistically come from: a mailer, a
notification channel, a debug or monitoring package, an update check. **Before
"nothing leaves my server" can be claimed for the deployed system as a whole,
the foundation's dependencies need the same scan.** Recorded in
`docs/OPEN_ITEMS.md` as a P1.

Everything in *this* repository is clean.

---

## 6. Summary

| Question | Answer |
|---|---|
| Does accounting data leave the server? | **No.** |
| Could this code send it? | **No.** No HTTP client is installed. |
| Is anything emailed? | **No.** `MAIL_MAILER=log`. |
| Any analytics, ads, telemetry, crash reporting? | **None.** |
| Any AI provider connected? | **None.** The AI boundary refuses eleven actions; no provider exists to refuse them for. |
| Any accountant or bookkeeping service integration? | **None.** |
| Government submission? | **Disabled.** Every filing channel throws; `FilingChannel::automated()` is `false` for all, and a test keeps it that way. |
| KSeF? | **Not connected.** No transport; `InvoiceRead` only when it exists. |
| Is the privacy property enforced mechanically? | **Not yet.** True in fact, not guarded. §4. |
| Was the ERP foundation audited? | **No.** Out of scope — §5. |
