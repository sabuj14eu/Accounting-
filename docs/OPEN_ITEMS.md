# Open items

Deferred work for the accounting application. An item deferred in conversation
is an item forgotten — if it is not here, it does not exist. Delete an entry
only when it is done and verified, and say where the proof is.

---

## P0 — must be settled before real accounting use

### The rate tables have not been verified against official sources
Every version in `modules/poland/config/rates/` is marked `secondary`: taken
from competent Polish accounting publications and cross-checked arithmetically
against its own stated formula. That catches transcription errors. It cannot
catch a wrong source.

Attempting the verification from the build environment is **impossible**, not
merely undone: zus.pl, gov.pl, isap.sejm.gov.pl, stat.gov.pl and api.nbp.pl are
all refused by the network egress policy (403 on CONNECT). It needs a human with
network access.

The software now refuses to hide this. Every report names the unverified tables
and lists them as a blocker to filing; `POLAND_REQUIRE_OFFICIAL_RATES=true`
makes the engine throw rather than settle; and preparation refuses to build a
document from a settlement that is not fit for filing.

Run `php artisan poland:rate-provenance --todo` for the worklist with the exact
URL for each figure. Procedure in `docs/RATE_VERIFICATION.md`.

Most critical single item: **whether the 2026 health-contribution reform (9% of
75% of the minimum wage) really did not take effect.** The secondary sources say
it was vetoed. If they are wrong, every 2026 health figure in the system is
wrong.

**Owner: the taxpayer's accountant. Proof required: `status => 'official'` with
a source URL on every version, plus the note here deleted.**

### Which ryczałt rate applies has not been determined
`examples/profile.json` ships 3% (trade in goods) as an example, not as advice.
The rate depends on what the business actually does under art. 12, a shop
selling goods and providing services may fall under two rates at once, and
choosing wrong changes the tax by multiples. The engine supports several rates
per taxpayer; the profile currently carries one.
**Blocked on: the taxpayer confirming their PKD activity and rate(s).**

### No cost register, so only ryczałt is exact
For the scale and the flat tax the engine can only bound PIT from above. This is
reported, not hidden, but it means those two regimes are not yet usable for a
real filing from cash-register data alone. Phase 2 (KPiR) closes it.

---

## P0 — found by the accounting gap audit (2026-09-09)

Full detail in `docs/ACCOUNTING_GAP_AUDIT.md`. Recorded here so they are not
lost in a long document.

### Most of the application has no way in
`BankImportService`, `KsefIngestService`, `MonthCloseService`,
`TransactionMatcher`, `DocumentClassifier` and `GovernmentDocumentModel` are
built, container-wired and tested, and **called by no route and no Artisan
command.** `MonthCloseService` — the ten-step month close — is registered in
`PolandServiceProvider` and invoked by nothing. The three commands are
`poland:report`, `poland:verify-rates`, `poland:rate-provenance`.
**Next step: decide the surface. A route-coverage test would stop this
recurring — every registered service reachable from a route or a command.**

### The data model is monthly; a food shop is daily
`pl_sales_reports` is unique on `(tax_profile_id, period, status)` and has **no
date column** — one row per month. `pl_purchase_summaries` is one row per month
with a single net total, a single input-VAT total and a document count.
So there is nowhere to store: a date, cash vs card vs Glovo, refunds, discounts,
cash expected vs counted vs difference, Z-report numbers, individual purchase
invoices, cost categories, or a supplier.
**This is a schema decision and it blocks everything else. Do not build screens
over the monthly model and migrate later.**

### No taxpayer-profile form, and half the profile is missing
`pl_tax_profiles` has no address, no bank account, no PKD and no CEIDG data —
and there is no create or edit screen for any of it, including `pit_regime`,
`lump_sum_rate` and `vat_status`, which change the tax by multiples. PKD is the
field that decides the ryczałt rate. NIP has no checksum validation.

### JPK_V7 cannot be produced at all
`SchemaRegistry` is constructed empty, and **no class in the codebase implements
`DocumentPreparer`.** `jpkStructure` on `VatSettlement` is a label naming which
structure would apply. For an active VAT taxpayer this is a monthly legal
obligation.

### No document upload path exists anywhere
`pl_government_documents.stored_path` is populated by nothing. There is no
upload route for a purchase invoice, a receipt, a bank statement or a government
letter. Stored files are not encrypted at rest (unlike the KSeF token) and have
no retention policy. Compounded by the missing OCR (below).

### Glovo has no representation in the accounting module
`PlatformSettlement` in `shop-intelligence/` models gross orders, commission,
fees, expected payout and the difference — with **no database, no UI, and no VAT
split on the commission**, which is deductible input VAT. `TransactionCategory`
has no platform-settlement case. It must not be built inside shop-intelligence:
that application has no path to a tax return, by design.

### Quarterly VAT is modelled but settlement is monthly throughout
Already listed under P1 below; raised to P0 visibility because a quarterly
taxpayer would be given the wrong period, silently.

## P1 — found by the accounting gap audit (2026-09-09)

### A logged-in user can read any taxpayer profile
`DashboardController::profileFor()` does `TaxProfileModel::find((int)
$request->query('profile'))` with **no ownership or tenancy check**, and all
nine routes sit behind `web` + `auth` with no per-profile authorisation. With
one taxpayer this is theoretical. It stops being theoretical at two.

### No suppliers, no cost categories, no payroll
No supplier table or concept (only denormalised strings on documents). No cost
category on any stored cost — your fifteen categories map to nothing. **No
payroll in any form**: no table, no model, no calculator, no test. Whether
payroll is P1 or P3 depends on a question nobody has answered — **does the shop
employ anyone.**

### No year-end: no annual return, no annual health reconciliation
No PIT-28 / PIT-36 / PIT-36L. Crossing a ryczałt revenue band mid-year creates a
year-end top-up; the report warns, it does not compute it. The holiday calendar
ends in 2027.

### No export of any kind
No CSV, PDF, XML or JSON export route; no accountant handover package; no GDPR
export or erasure path. The append-only audit trail is correct for accounting
and in real tension with erasure — that tension should be written down, not
discovered later. `actor_ip` is personal data with no stated retention period.

### The privacy property is true but not enforced
`docs/DATA_PRIVACY_AND_EXTERNAL_CONNECTIONS.md` verifies that **no accounting
data leaves the server and no code here could send any** — zero outbound
primitives, zero URL literals in application source, no HTTP client in either
composer.json, `MAIL_MAILER=log`, no analytics, no AI provider. But there is no
privacy equivalent of `bin/check-isolation.sh`: nothing would fail if somebody
added Guzzle and posted a settlement outward.
**Next step: `bin/check-privacy.sh` plus a declared destination allowlist (today
it would hold two inactive entries, KSeF and NBP), run in CI and pre-deploy.**

### The Liberu ERP foundation has not been privacy-audited
This repository is a module; the host Laravel application is installed, not
vendored, and its dependency tree was **not** scanned. That is where an outbound
path would realistically come from — a mailer, a notification channel, a
monitoring or debug package, an update check. **"Nothing leaves my server"
cannot be claimed for the deployed system until the foundation gets the same
scan.**

## P1 — needed for a complete Phase 1

### Polish chart of accounts and company defaults not configured
The Liberu foundation ships its own chart of accounts. Polish account numbering,
VAT registers and document types have not been configured. The foundation itself
now boots and migrates — see `docs/SUPPORTED_VERSIONS.md` — so this is
configuration work, not a runtime unknown.

### KSeF HTTP transport is not implemented
`ksef.mf.gov.pl`, `ksef-test.mf.gov.pl` and every variant are refused by the
build environment's network policy, so no client could be written against the
real API or tested against it. Writing one from remembered documentation would
produce something that looks finished and fails on first contact.

Everything around it IS built and executed: the FA parser, dedup, incremental
cursor, immutable XML, encrypted InvoiceRead-only tokens, REQUIRES REVIEW
propagation. `UnconfiguredKsefClient` refuses until a transport exists.
**Next step: implement `KsefClient` against the current official API, verified
at source, and test against the KSeF test environment.** See `docs/AUTOMATION.md`.

### KSeF has no user interface at all
Found on 2026-09-09 while writing `docs/KSEF_USER_GUIDE.md`. The KSeF machinery
is built and tested; **none of it is reachable from a browser.** A customer
cannot see, configure, start or check anything about KSeF except one status row.

What was searched for and does not exist:
- **No `Admin` menu, no `Tax & Compliance` section, no `KSeF` page.** The module
  serves exactly nine routes (`modules/poland/routes/web.php`) and none is a
  KSeF route. Only three Blade templates exist: `dashboard`, `report`, `history`.
- **No configuration wizard** — no environment step, no seller step, no token
  step, no defaults step, no "test and power on" step.
- **No "Test connection" button**, no success/failure banner.
- **No token entry form.** Credentials reach the system only as a row written
  directly into `pl_ksef_credentials`, plus `.env`.
- **No taxpayer profile create/edit form.** The dashboard *reads* profiles and
  shows "Najpierw skonfiguruj profil podatnika." when there is none, but offers
  nowhere to create one. Every one of the ~20 profile fields is
  administrator-only, including the ones that change the tax by multiples
  (`pit_regime`, `lump_sum_rate`, `vat_status`).
- **No way to trigger a KSeF sync.** `MonthCloseService` is registered in
  `PolandServiceProvider` and **called by no route and no Artisan command**. The
  module's three commands are `poland:report`, `poland:verify-rates`,
  `poland:rate-provenance`. So `KsefIngestService::sync()` — which is built,
  tested and correct — has no caller in the shipped application.

The single status row that does exist is the KSeF entry in the "Stan integracji"
card on `/poland`, and it is accurate.

**Next step: decide whether KSeF configuration is meant to be a customer-facing
screen or to stay administrator-only. If customer-facing, it needs a taxpayer
profile form first — the profile is the prerequisite and it has no form either.**
Nothing here is a defect in the KSeF code; it is a missing surface.

### The taxpayer profile has no address fields
`pl_tax_profiles` carries `name`, `nip`, `regon` and the tax-regime columns, and
**no street, building, flat, postcode, city, voivodeship, country, email, phone,
bank account or PKD code.** Enough for KSeF's NIP-based matching and for the tax
engine; not enough for anything that has to print or transmit a seller identity.
Worth settling deliberately before a future feature discovers it by failing.

### Four accepted request fields have no input on the form
`DashboardController::storeSales()` validates `designation`, `register_id` and
`report_number`, and `storeCosts()` validates `note`. **`dashboard.blade.php`
renders an input for none of them.** So from a browser the VAT designation can
never be overridden (it is always derived from the profile), and a cash-register
identifier or report number can never be recorded — which matters because a
sales figure with no register/report reference cannot be reconciled against the
register's own paper trail. Either render the inputs or drop the validation
rules; carrying both makes the contract look wider than it is.

### Invoice issuing and submission were requested and do not exist by design
Recorded so the refusal is written down rather than rediscovered. There is no
outgoing-invoices page, no invoice creation, and no submission path, in TEST or
production. Three independent mechanisms enforce it and each is tested:
`KsefScope::allowed()` returns `InvoiceRead` only, `FilingChannel::automated()`
is `false` for every channel, and `IntegrationStatus::governmentSubmission()` is
hard-coded to `DISABLED`. **This is not a gap to close casually.** Issuing an
invoice in a taxpayer's name is a materially different act from reading their
inbox and would need a deliberate decision with a stated reason, not a
configuration change.

### The KSeF user guide is in English, and the application is in Polish
`docs/KSEF_USER_GUIDE.md` quotes every on-screen string verbatim in Polish with
a translation, but its own prose is English, matching the rest of `docs/`. The
intended reader is a Polish sole trader. **A Polish translation is needed before
it is handed to a customer.** It is also not wired into the application as a
page — it is a repository document, because adding a route or a view would have
been an application-code change.

### No OCR or PDF text extraction
No `tesseract`, no `pdftotext` in the environment. `UnavailableTextExtractor`
refuses, and `PdfStatementParser` refuses with a route forward (CSV/MT940/camt).
Government PDFs are stored and marked for manual review rather than silently
classified as "information only".
**Next step: install a text-extraction toolchain on the server and implement the
`TextExtractor` port.**

### No KSeF or JPK schema version is registered
`SchemaRegistry` selects a schema by the period being filed and refuses periods
it has no version for, and `PreparedDocument` treats "could not be validated" as
distinct from "valid". No actual XSD is registered, so no document can be
prepared yet. Phase 3/4.

### Filing is not implemented for any channel
`UnconfiguredSubmitter` is bound for every channel and throws. That is
deliberate: the filing stage exists in the model, and the honest state of it is
that nothing is sent anywhere. A taxpayer files themselves and records the
reference through `markFiled()`.

### No exchange-rate adapter
`UnavailableExchangeRateProvider` refuses rather than defaulting. A missing rate
is not 1.0 and is not yesterday's rate. NBP adapter is Phase 2.

### Backup and restore have not been drilled on a real database
`bin/data-safety-drill.sh` and `deploy/backup.sh` are written and
syntax-checked, but this environment has no MariaDB server, so neither has ever
restored anything. **Run the drill on the Contabo box before going live** — it
must exit 0.

### Multi-rate ryczałt is supported by the engine but not by data entry
`FiscalSalesReport` lines carry an optional per-line ryczałt rate and
`PitCalculator` apportions deductions proportionally (tested). The dashboard and
the CLI only accept one rate. Fine for a single-activity business; wrong for a
mixed one.

### Quarterly VAT (JPK_V7K) is modelled but not settled quarterly
The profile carries the frequency and the report names the right structure, but
settlement is monthly throughout. A quarterly taxpayer would need the VAT part
aggregated over the quarter.

---

## P2 — known limitations, acceptable for now

### Suspension and sickness do not shorten a month
Only an incomplete FIRST month prorates social contributions. A business
suspension or a sickness period also reduces the base, but the engine is not
told about them, so it computes a full month and the report states that
assumption rather than pretending to know.

### Mały ZUS Plus base is supplied, not derived
The base depends on the previous year's income and the 36-in-60-months
entitlement limit depends on the taxpayer's own history. Both are configured and
validated against the statutory bounds; neither is computed.

### The public holiday calendar ends in 2027
`config/rates/deadlines.php` carries 2025–2027. Beyond that the report returns
the statutory date and says the working-day shift could not be applied. Add years
before then.

### Annual reconciliation of the health contribution is warned about, not computed
Crossing a ryczałt revenue band mid-year creates a top-up at year end. The report
warns; it does not calculate the annual settlement. That belongs with the annual
return (PIT-28), which is not built.

---

### Three bugs were found by executing the Laravel layer, and fixed
Recorded because they are the argument for the integration job in CI:
1. `RateProvenance` constructor parameters were declared in a different order
   than `fromArray()` passed them positionally. Fixed, and the call now uses
   named arguments so it cannot recur; pinned by
   `test_provenance_fields_are_read_in_the_right_order`.
2. A sales correction inserted the new report before superseding the old one,
   violating the unique index that makes duplicate months impossible. Order was
   load-bearing and is now commented as such.
3. The dashboard view assumed `$errors` is always bound, which is only true
   inside the `web` middleware group. Guarded.

None of these were visible to `php -l`, and none were visible to the engine's
own test suite.

## Shop Profit Intelligence — not built yet

The analysis core in `shop-intelligence/` is built and tested (60 tests, 469
assertions, 0 failures). Everything around it is not, and the list is short
enough to be honest about:

1. No user interface — the three pages exist as a design in
   `shop-intelligence/docs/BANKING_UX.md` and as a text renderer, nothing else.
2. No database, no migrations, nothing persists.
3. No authentication, no users, no sessions. `.env.example` describes them.
4. No importers: bank statement, card terminal, Glovo, Uber Eats, supplier
   invoices, OCR — all currently a human typing figures into a constructor.
5. No month close and no immutable snapshot. The rule is written down; there is
   no table to enforce it in.
6. Not deployed: no database created, no vhost, no systemd unit, no backup.
7. **The 27 regression tests were derived from the specification body, not
   transcribed from its §29 list.** Somebody must read §29 line by line against
   `shop-intelligence/docs/REGRESSION_MAP.md` and report what is missing.
   Overlap is not coverage.

Every threshold in it is invented — target margin, stock tolerance, revenue and
cost alert levels, the payout tolerance, the three-period baseline minimum. None
came from this shop's data. `shop-intelligence/docs/` and
`docs/FABLE_BRIEFING_2026-09-08.md` §5 list where I am most likely wrong.

**Owner: whoever picks up the briefing. Proof required: a deployed application
with its own database and its own login, and the §29 reconciliation written
down.**

## Deliberately not done

### The SignalMesh navigation link (Phase 6)
Not applied. The instruction was to start with Phase 1, and the trading platform
is not modified without a reason and a deliberate decision.
`docs/SIGNALMESH_NAV_LINK.md` has the exact change.

### Single sign-on with the trading platform
Phase 1 has its own login by design. SSO would be a deliberate OAuth/OIDC
integration between two applications that stay separate — never a shared session
store, which would turn an outage of either into an outage of both.
