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

## P1 — the redesigned accounting workflow (stage B built 2026-09-10; stages C–F not started)

`docs/ACCOUNTING_WORKFLOW_AND_DATA_MODEL.md` is the binding design: automatic
KSeF incoming invoices → review → approval → purchase/VAT posting → optional
per-product inventory; monthly sales (shop + Glovo); Glovo as a settlement
source; VAT → JPK_V7 → PIT → ZUS. Stages B–F in its section 15 are each a
separate approval. **Stage B is built and unit-tested** (CHANGELOG 2026-09-10
second entry): review inbox, postings, optional inventory, Glovo settlement,
sales channels. Its Laravel layer has NOT yet been run against a database
here (PHP 8.5 + the foundation are needed) — the `laravel-integration` CI job
and the Contabo box are where that proof comes from. Until then the migrations
and screens are "written and linted", not "executed".

**Stage C is built (2026-09-11, CHANGELOG "2026-09-11 (second)")** — the
real transport against OpenAPI 2.7.1, disabled by default, **never yet run
against any KSeF environment**. Its live acceptance is the item below.

Still to build: **D** Glovo statement importer · **E** JPK_V7 preparation ·
**F** trusted-supplier auto-approval.

Decisions the design deliberately leaves to the owner and the accountant, and
refuses to post without:
- **Glovo VAT treatment** for this contract — domestic commission invoice or
  import of services (`vat_treatment`, section 7.2).
- **JPK document type for Glovo orders** — through the register as RO or a
  separate summary (section 10).
- **Is the shop a registered VAT payer or exempt under art. 113?** The profile
  decides; the design assumes registered for its worked example only.
- Foundation hardening on the box per section 14.3–14.5 (Telescope off, unused
  modules disabled, no third-party keys, CSP header) — verified on the server,
  not from a clone.

**Proof required to delete this entry: stage B migrations applied and the
inbox → approve → posting → stock flow executed on a real database (CI job or
the box), stages C–F each shipped or explicitly cancelled, and the four
decisions above recorded in the profile and the audit trail.**

## P1 — the rate tables end in January 2027 (CI `rate-coverage` is RED, correctly)

`zus_social` covers through 2027-01 and `zus_health` through 2027-01; the CI job
looks six months ahead and, from September 2026, reaches February 2027 and
fails. **This is the check working, not a defect in the check.** The 2027
figures (minimum wage set by regulation in September 2026; the forecast average
wage in the 2027 budget act; the resulting preferential and full bases and the
health bands) must be read from the issuing authorities by a human with network
access and entered as new versions with provenance, exactly as
`docs/RATE_VERIFICATION.md` requires. Nothing here extrapolates 2026 into 2027.

**Owner: the taxpayer's accountant. Proof required: 2027 versions in
`config/rates/zus_social.php` and `zus_health.php` with sources, the CI job
green, and this entry deleted.**

## P1 — needed for a complete Phase 1

### Polish chart of accounts and company defaults not configured
The Liberu foundation ships its own chart of accounts. Polish account numbering,
VAT registers and document types have not been configured. The foundation itself
now boots and migrates — see `docs/SUPPORTED_VERSIONS.md` — so this is
configuration work, not a runtime unknown.

### KSeF transport is built but has never touched a KSeF environment
`HttpKsefClient` was written from the official specification (`CIRFMF/ksef-docs`
open-api.json 2.7.1, commit `93b843d`) and is covered by 27 unit tests against
recorded responses — but the build environment cannot reach `*.ksef.mf.gov.pl`,
so **no request has ever been sent to KSeF.** Recorded shapes are what the
spec says; the first live run is where the spec and the server may disagree.

What is needed, in order (procedure in `docs/KSEF_GO_LIVE.md`):
1. the owner generates a **TEST** token with `InvoiceRead` only for a random
   test NIP (the application refuses to hold the credential class that could
   do this itself);
2. on the server: `KSEF_TRANSPORT=real`, `KSEF_TRANSPORT_ENABLED=true`,
   `KSEF_EGRESS_ENABLED=true` with `KSEF_EGRESS_AUTHORISED_BY/ON`, then
   `poland:ksef-token --environment=test` and `poland:ksef-check`;
3. the twelve verdicts pasted into `docs/KSEF_GO_LIVE.md` §4 — steps 6, 7 and
   9 need invoices in the test inbox (more than ten for step 7); steps 8 and
   10 can only ever be NOT TESTED live and must be accepted on unit evidence
   explicitly;
4. the systemd egress allowlist (`deploy/systemd/egress-allowlist.conf.example`)
   installed with the one environment's resolved addresses.

**No production token exists and none may be created until the record in
`docs/KSEF_GO_LIVE.md` §4 says yes.** `poland:ksef-token` refuses a production
token without `--go-live-passed-on=DATE`.

**Proof required to delete this entry: a filled results block in
`docs/KSEF_GO_LIVE.md` §4 for the TEST environment with steps 1–7, 9, 11, 12
PASS, the audit row `ksef.check_run` with `passed`, and — for production —
a second block against production plus a first hand-run sync compared with
the owner's own purchase knowledge.**

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

## P2 — upstream Liberu registers a non-existent views directory

`php artisan view:cache` aborts on the foundation with "The
…/accounting-quickbooks-online-migration-livewire/src/../resources/views
directory does not exist" (Symfony Finder). Upstream bug in a module this shop
never uses. `bin/update-app.sh` clears the view cache and treats a failed
precompile as a warning; views compile on demand. Report upstream or disable
the module in the module manager when the foundation is next upgraded.

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
