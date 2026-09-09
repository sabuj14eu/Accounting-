# Accounting gap audit — sole-proprietor food shop (Super Kebab Lębork)

**Audited:** 2026-09-09. **No code was changed.** Routes, controllers, models,
migrations, views, services and schema are untouched.

**Method.** Every claim below was read out of source — the twelve migrations,
fifteen Eloquent models, two controllers, nine routes, three Blade templates,
the service provider's bindings, the three Artisan commands, and the
framework-free engine in `modules/poland/src`. Previous documentation was
treated as a claim to check, not as evidence. Where a document described a
screen, the route table decided whether it exists.

**Subject.** A VAT-registered Polish JDG running a kebab shop with counter
sales, card sales and Glovo delivery.

**Companion document:** `docs/DATA_PRIVACY_AND_EXTERNAL_CONNECTIONS.md`
answers "does anything leave the server" (short answer: no, and no code here
could).

---

## Part 0 — The verdict in one page

### Scoreboard

| # | Area | Engine | Storage | Way in | Verdict | Pri |
|---|---|---|---|---|---|---|
| 1 | Company profile / CEIDG | ✅ | ⚠️ partial | ❌ | Half the fields don't exist; no form | **P0** |
| 2 | Sales | ✅ | ⚠️ monthly only | ⚠️ 3 fields | Monthly totals only, no channels | **P0** |
| 3 | Cash register / daily Z | ✅ | ❌ | ❌ | No daily entity at all | **P0** |
| 4 | Glovo settlements | ⚠️ elsewhere | ❌ | ❌ | Modelled in shop-intelligence, no DB | **P0** |
| 5 | Purchases | ✅ | ⚠️ one monthly total | ⚠️ 4 fields | No individual invoices | **P0** |
| 6 | Suppliers | ❌ | ❌ | ❌ | No table, no concept | **P1** |
| 7 | Bank transactions | ✅ strong | ✅ strong | ❌ | Built and tested; unreachable | **P0** |
| 8 | Expenses / categories | ❌ | ❌ | ❌ | No cost categories exist | **P0** |
| 9 | VAT | ✅ strong | ✅ | ⚠️ | Works; input VAT is one number | **P1** |
| 10 | JPK_V7 | ❌ | ⚠️ table only | ❌ | Not built. No preparer exists | **P0** |
| 11 | PIT | ✅ strong | ✅ | ⚠️ | Correct; exact only on ryczałt | **P1** |
| 12 | ZUS | ✅ strong | ✅ | ✅ | The most complete area | **P2** |
| 13 | Payroll | ❌ | ❌ | ❌ | Does not exist in any form | **P1** |
| 14 | KSeF | ✅ around it | ✅ | ❌ | No transport, no UI | **P1** |
| 15 | Reports | ✅ strong | ✅ strong | ✅ | Genuinely good | **P2** |
| 16 | Year-end | ❌ | ❌ | ❌ | No annual return, no reconciliation | **P1** |
| 17 | Document storage | ⚠️ | ⚠️ gov only | ❌ | No upload path anywhere | **P0** |
| 18 | Audit trail | ✅ strong | ✅ strong | n/a | Append-only, enforced | **P2** |
| 19 | Backups | ✅ written | n/a | n/a | Never restored anything | **P0** |
| 20 | Privacy / export | ✅ private | n/a | ❌ | Private; but no export at all | **P1** |

Legend: ✅ built and tested · ⚠️ partial · ❌ absent.
"Way in" = can a person actually get data in through a browser.

### The three findings that outrank everything else

**FINDING 1 — Most of the software has no way in.**

`BankImportService`, `KsefIngestService`, `MonthCloseService`,
`TransactionMatcher`, `DocumentClassifier` and `GovernmentDocumentModel` are
built, wired into the service container, and covered by tests. **Not one of
them is called by any route or any Artisan command.** The three commands are
`poland:report`, `poland:verify-rates`, `poland:rate-provenance`; none touches
them. `MonthCloseService` — the ten-step month close — is registered in
`PolandServiceProvider` and invoked by nothing.

The application's nine routes reach the settlement engine and the report. They
reach nothing else. **This is not a code-quality problem; it is a missing
surface, and it is why the app feels emptier than the test count suggests.**

**FINDING 2 — The data model is monthly, and a kebab shop is daily.**

`pl_sales_reports` is unique on `(tax_profile_id, period, status)` and has **no
date column**. One row per month. `pl_purchase_summaries` is one row per month:
a single net total, a single input-VAT total, a document count.

Everything you asked for in daily sales — date, cash vs card vs Glovo, refunds,
discounts, cash expected vs counted vs difference — has **nowhere to be
stored**. Same for cost categories: meat, packaging, rent and electricity all
collapse into one number. This is a schema change, not a form change, and it
should be settled before anything is built on top.

**FINDING 3 — Two P0 blockers are unrelated to features and block real use
anyway.**

- **No rate table is verified.** Every version in `config/rates/` is marked
  `secondary`. The engine says so on every report and
  `POLAND_REQUIRE_OFFICIAL_RATES=true` makes it refuse to settle. Until a human
  with network access verifies them against ZUS/isap/MF publications, **no
  figure this system produces may be paid.**
- **Backup and restore have never been drilled.** `deploy/backup.sh` and
  `bin/data-safety-drill.sh` are written and syntax-checked; this environment
  has no database server, so neither has ever restored anything.

Building features on top of either is building on an unverified foundation.

---

## Part 1 — Area by area

Each area: **exists · works · missing · UI-or-doc-only · data stored · leaves
the server · what an accountant expects · priority · tests required.**

---

### 1. Company profile / CEIDG — **P0**

**Exists.** `pl_tax_profiles`, `TaxProfileModel`, the framework-free
`Poland\Domain\TaxProfile`, `ProfileFactory`, and `examples/profile.json`.

**Works.** Every field that exists is used and drives the calculation. Regime,
VAT status, ZUS scheme, sickness insurance, accident rate, business start month
and day, health-band settings, `cash_register_letters`. Covered by
`tests/Unit/TaxProfileTest.php`.

**Missing.**

| You asked for | Status |
|---|---|
| Business name | ✅ `name` |
| NIP | ✅ `nip` |
| REGON | ✅ `regon` |
| **CEIDG information** | ❌ no columns — no CEIDG number, no registration date, no status |
| **Business address** | ❌ **no address columns at all** — no street, building, flat, postcode, city, voivodeship, country |
| **Bank account** | ❌ no column |
| **PKD** | ❌ no column — and this is the field that decides the ryczałt rate |
| VAT status | ✅ `vat_status` |
| VAT settlement period | ✅ `vat_settlement` (see §9) |
| Income-tax method | ✅ `pit_regime` + `lump_sum_rate` |
| Accounting period | ⚠️ monthly throughout; the profile carries frequency but settlement ignores it |

**UI/doc only.** **There is no create or edit form for the taxpayer profile.**
The dashboard reads the first profile by id (or `?profile=<id>`) and shows
*"Najpierw skonfiguruj profil podatnika"* when there is none — with nowhere to
configure it. Every field, including the ones that change the tax by multiples,
is administrator-only, entered directly into the database.

**Data stored.** Name, NIP, REGON, regime, rate, VAT status and frequency, ZUS
scheme, sickness flag, accident rate, Mały ZUS Plus base, start month/day,
deduction basis, health-band flags, previous-year revenue, cash-register letter
map.

**Leaves the server.** No.

**An accountant expects.** Full CEIDG identity, registered address, business
address if different, bank account for tax and ZUS transfers, PKD codes, and
the ability to see and correct all of it themselves.

**Tests required.** Profile CRUD authorisation; NIP checksum validation (Polish
NIP has a weighted check digit — nothing validates it today); IBAN validation;
PKD format; a test that a regime change is audited with actor and old/new
value; a test that the profile form cannot silently change a closed period's
basis.

---

### 2. Sales — **P0**

**Exists.** `pl_sales_reports` + `pl_sales_report_lines`, `SalesReportModel`,
`SettlementRecorder::recordSales()`, `FiscalSalesReport`, `SalesLine`, and the
sales form on `/poland`.

**Works.** Well, for what it models. Gross → net + VAT split per VAT
designation; per-line optional ryczałt rate; corrections supersede rather than
overwrite (`superseded_by_id`, `correction_reason`, and the superseded row
stays); a unique index makes two active reports for one month impossible.
`tests/Feature/KasaFiskalnaReportTest.php` covers it.

**Missing — everything daily.**

| You asked for | Status |
|---|---|
| **Date** | ❌ **no date column.** The grain is `period` (`YYYY-MM`) |
| **Cash-register sales** | ⚠️ only as the single monthly gross total |
| **Card sales** | ❌ no channel concept in the accounting module |
| **Glovo sales** | ❌ see §4 |
| **Other delivery** | ❌ |
| VAT rate / category | ✅ `designation` per line |
| Gross sales | ✅ |
| **Refunds** | ❌ no representation |
| **Discounts** | ❌ no representation |
| **Daily total** | ❌ no daily grain |
| **Cash expected / counted / difference** | ❌ see §3 |

**UI/doc only.** The form renders **three** inputs: *Miesiąc*, *Sprzedaż
brutto z kasy fiskalnej*, *Przyczyna korekty*. `DashboardController::storeSales()`
additionally validates `designation`, `register_id` and `report_number` — **no
input is rendered for any of them.** So from a browser the VAT designation can
never be overridden (it is always derived: `0.23` if you settle VAT, `zw` if
exempt) and a register or report number can never be recorded.

**Data stored.** Period, register id, report number, gross/net/VAT totals,
status, supersession link, correction reason, note; per line: designation,
gross, net, VAT, optional ryczałt rate, note.

**Leaves the server.** No.

**An accountant expects.** A daily sales record tied to the Z-report that
produced it, split by VAT rate and by payment channel, with refunds and
discounts visible, reconcilable to the cash count and the card terminal.

**Tests required.** Daily entry with a real date; two Z-reports on one day;
per-channel totals summing to the daily total; refunds reducing revenue and
output VAT correctly; a month assembled from days matching a directly entered
monthly total; a correction to one day not disturbing the others; VAT-rate
split preserved from day to month.

---

### 3. Cash register / daily Z-reports — **P0**

**Exists.** `register_id` and `report_number` columns on `pl_sales_reports`.
`cash_register_letters` on the profile (maps register letters A/B/C to VAT
rates). In the *other* application, `shop-intelligence/src/Cash/CashLedger.php`
and `CashMovement.php` model a cash drawer properly.

**Works.** The letter map works. The framework-free `CashLedger` is tested
(`shop-intelligence/tests/`).

**Missing.** **There is no Z-report entity.** No daily row, no report sequence
number enforced, no gap detection between Z-report numbers, no cash counted, no
cash expected, no difference, no till float, no cash drops, no
`REQUIRES REVIEW` on a discrepancy. `CashLedger` **has no database, no
migration and no UI** — it is a pure PHP class nothing persists.

**UI/doc only.** `register_id` and `report_number` exist in the schema and in
validation but have **no form input** — so in practice they are always null.

**Data stored.** Effectively nothing about the register beyond a nullable id
and number that no screen can set.

**Leaves the server.** No.

**An accountant expects.** Every Z-report recorded in sequence, numbered, dated,
split by VAT letter, with the cash count against it — because an unexplained
cash difference is the single most-examined thing in a cash business, and a gap
in Z-report numbers is what an inspection looks for first.

**Tests required.** Sequence-gap detection; duplicate Z-number refused; daily
letter totals matching the register's own totals; cash expected = opening +
cash sales − drops − refunds, and the difference surfaced as REQUIRES REVIEW
never as an accusation (the `ReviewFlag` vocabulary rule already exists in
shop-intelligence and should govern here too); a month's Z-reports summing to
the monthly sales report.

---

### 4. Glovo settlements and commissions — **P0**

**Exists — but in the other application.**
`shop-intelligence/src/Platforms/PlatformSettlement.php` models: platform,
period, gross orders, commission, other fees, expected payout, the actual bank
receipt as a `Figure`, the difference, effective commission rate, a tolerance,
and `reviewFlags()`. `RevenueChannel` has `GLOVO` and `UBER_EATS` cases.

**Works.** The arithmetic and the review logic are built and tested.

**Missing.**

- **Nothing in the accounting module.** No table, no model, no route, no
  category. `TransactionCategory` has `KsefInvoicePayment`, `Expense`,
  `Revenue`, `ZusPayment`, `TaxPayment`, `Recurring`, `Transfer`,
  `NeedsReview` — **no platform-settlement category.**
- **`PlatformSettlement` has no database and no UI.** shop-intelligence has no
  migrations at all.
- **No VAT on commission field.** You listed it and it is the part that
  actually matters: Glovo's commission is a purchased service, its VAT is
  deductible input VAT, and `PlatformSettlement` models commission as a single
  gross figure with no tax split.
- No settlement/invoice number, no settlement date, no accounting category, no
  link from a settlement to the bank transaction that paid it.
- **The two applications share nothing by design** — no database, no table, no
  code path. So a Glovo settlement recorded in shop-intelligence can never
  reach a VAT return. The only sanctioned route between them is a manual
  `AccountsSnapshot`, compared and never auto-corrected.

**UI/doc only.** The three shop-intelligence pages exist as a design in
`shop-intelligence/docs/BANKING_UX.md` and a text renderer. Nothing else.

**Data stored.** Nothing, in either application.

**Leaves the server.** No — and note there is **no Glovo API client**. A
settlement would be entered or imported by hand.

**An accountant expects.** For each Glovo settlement: gross order value,
commission net + VAT + gross, other deductions, net payout, the settlement
document number and date, the input VAT claimed on the commission, and the bank
receipt matched to it. Glovo revenue is *your* revenue at gross; the commission
is *your* cost. Netting them off understates both turnover and costs — which
matters for the VAT-exemption threshold and for ryczałt.

**Priority note.** This is P0 **for the shop's real numbers** and P0 for VAT
(the commission's input VAT is real money), but it must not be built inside
shop-intelligence, because that application has no path to a tax return by
design. It belongs in the accounting module as a first-class document.

**Tests required.** Gross-not-net revenue recognition; commission VAT
deductible in the right period; settlement matched to a bank receipt within
tolerance; a difference raising REQUIRES REVIEW with innocent explanations and
never an accusation; a settlement spanning a month boundary; a negative
settlement (refunds exceeding orders); the same settlement imported twice
rejected by a unique key.

---

### 5. Purchases / costs — **P0**

**Exists.** `pl_purchase_summaries` (one row per month), `PurchaseSummaryModel`,
`Poland\Domain\PurchaseRegister`, `SettlementRecorder::recordPurchases()`, and
the collapsed costs form on `/poland`. Separately, `pl_ksef_documents` can hold
real individual invoices — but only ones downloaded from KSeF, and there is no
transport (§14).

**Works.** The monthly total flows correctly into VAT (input VAT) and PIT
(deductible costs), and the engine is explicit that without it the result is an
**upper bound** rather than a figure to pay.

**Missing.** Everything itemised.

| You asked for | Status |
|---|---|
| meat, vegetables, bread, sauces, drinks, packaging | ❌ **no category field exists** |
| electricity, gas, rent, cleaning | ❌ same |
| equipment, repairs | ❌ same — and no fixed-asset register, no depreciation |
| Glovo fees | ❌ §4 |
| telephone/internet, accounting | ❌ same |
| employee costs | ❌ §13 |
| **Upload/store the invoice** | ❌ §17 — no upload path exists |
| Individual purchase invoice records | ❌ only a monthly total |
| Supplier | ❌ §6 |
| Invoice number, date, due date, payment status | ❌ |

**UI/doc only.** The costs form is inside a collapsed `<details>` and renders
four inputs: *Miesiąc*, *Koszty netto*, *VAT naliczony*, *Liczba dokumentów*.
`storeCosts()` also validates `note` — **no input is rendered for it.**

**Data stored.** Per month: deductible costs net, deductible input VAT,
document count, note. That is the entire purchase side of the business.

**Leaves the server.** No.

**An accountant expects.** A purchase register (rejestr zakupów VAT): one row
per invoice with supplier, NIP, invoice number, issue date, receipt date, net
per VAT rate, VAT per rate, gross, deductible flag, cost category, payment
status, and the document itself attached. For a KPiR taxpayer this is a legal
record, not a convenience.

**Tests required.** Per-invoice entry with category and supplier; input VAT
deductible in the correct period (invoice date vs receipt date); partially
deductible items (e.g. a vehicle); a purchase invoice arriving after the month
is settled marking it REQUIRES REVIEW (the mechanism already exists for KSeF —
`KsefIngestService::flagAffectedReports()` — and should cover manual entry);
duplicate invoice number per supplier rejected; category totals summing to the
monthly total.

---

### 6. Suppliers — **P1**

**Exists.** Nothing. There is **no supplier table and no supplier concept.**

The nearest things: `seller_nip` / `seller_name` denormalised onto
`pl_ksef_documents`, and `counterparty` / `counterparty_account` on
`pl_bank_transactions`. Both are strings copied onto a document, not records.

**Works.** n/a.

**Missing.** Supplier master, NIP, address, bank account, default cost
category, default VAT treatment, payment terms, contact, and the link from an
invoice to a supplier record. Without it: no "what did I spend at this meat
supplier this year", no automatic categorisation of a recurring supplier, and
no reliable matching of a bank transfer to a supplier.

**Data stored.** Only strings on documents.

**Leaves the server.** No — no GUS/CEIDG/VIES lookup exists.

**An accountant expects.** A supplier register with NIP and bank account, and
VAT-status verification (biała lista) for larger payments — which is a legal
consideration for transfers over 15 000 zł.

**Tests required.** NIP checksum; a supplier's invoices aggregating correctly;
merging duplicate suppliers preserving history; a bank transfer matched to a
supplier by account number; deleting a supplier with invoices refused.

---

### 7. Bank transactions — **P0 (surface only)**

**Exists, and this is one of the strongest parts of the system.**
`pl_bank_statements`, `pl_bank_transactions`, `pl_transaction_classifications`;
`BankImportService`; parsers for **camt.053, MT940 and CSV**;
`PdfStatementParser` which *refuses* with a route forward.

**Works.** Genuinely well:

- **Balance reconciliation** — `balances_reconcile`, `opening_balance`,
  `closing_balance`, `problems`.
- **Completeness** — `completeness`, `covered_range`, `has_balances`, defaulting
  to `UNKNOWN` rather than to "complete".
- **Duplicate detection** at two levels: a unique `(tax_profile_id,
  fingerprint)` index, plus a softer `possible_duplicate_of` +
  `duplicate_reason` + `duplicate_decision` for a human.
- **Statement-level dedup** by checksum.
- **PDF is not a source of transactions** — it refuses rather than parsing
  approximately.
- Covered by `tests/Feature/ReconciliationTest.php` and
  `tests/Unit/SyncSafetyTest.php`.

**Missing.** **A way to use it.** There is no upload route, no upload form, no
Artisan command. `BankImportService` is registered in the container and called
only by `MonthCloseService`, which nothing calls. Also missing: multiple bank
accounts per taxpayer, a card-terminal settlement import, and any link from a
transaction to a Glovo settlement (§4).

**UI/doc only.** The entire feature.

**Data stored.** Statement metadata + checksum + problems; per transaction:
booking and value date, amount, direction, description, counterparty and
account, reference, balance after, currency, fingerprint, period, raw payload,
duplicate links; per classification: category, confidence, reason, match
quality, matched document, auto-bookable flag, decision + who + when + note.

**Leaves the server.** No. Files are uploaded by a human; there is **no bank
API, no PSD2 client, no screen-scraping**.

**An accountant expects.** All business bank accounts imported, every
transaction classified, income matched to sales and payments matched to
invoices, with an auditable trail of who decided what.

**Tests required.** Upload authorisation and file-type restriction; upload size
limits; the same statement uploaded twice; overlapping statements; a statement
with a gap detected; malformed file rejected without a partial import;
multi-account separation; a test that an import is audited with the actor.

---

### 8. Expenses / cost categories — **P0**

**Exists.** In the accounting module: **nothing.** One monthly net total (§5).
In shop-intelligence: `CostCategory` with **three** cases —
`FOOD_AND_MATERIALS`, `PREMISES_AND_UTILITIES`, `OTHER_OPERATING` — plus
`CostEntry` and `CostEntryType`. No database.

**Works.** The shop-intelligence classes are tested as pure logic.

**Missing.** Your fifteen categories against three coarse buckets in an
application that cannot file anything, and zero in the one that can. No
category on any stored cost; no per-category reporting; no recurring-cost
schedule (rent, accounting fee, telephone); no fixed assets or depreciation;
no distinction between a fully deductible cost and a partially deductible one.

**Data stored.** One net total and one input-VAT total per month.

**Leaves the server.** No.

**An accountant expects.** A KPiR-compatible cost classification, and for a food
business a split fine enough to see food cost as a percentage of revenue —
which is the number that decides whether a kebab shop is viable.

**Tests required.** Category totals reconciling to the month; recurring cost
generated once per month and never twice; a re-categorisation audited with old
and new value; a category change on a closed month refused or forced through a
correction.

---

### 9. VAT — **P1**

**Exists.** `VatCalculator`, `VatSettlement`, `VatStatus`,
`VatSettlementFrequency`, versioned `config/rates/vat.php`, and the VAT section
of the report. Tested in `tests/Unit/VatCalculatorTest.php`.

**Works, and carefully.**

- Output VAT computed per sales line from the register — exact.
- Input VAT taken from the purchase register.
- **When no purchase register is present the result is labelled an UPPER
  BOUND**, not quietly presented as the amount to pay.
- Carry-forward in and out (`carryForward`) — a VAT surplus rolls forward.
- Exempt taxpayers handled by a separate path, with turnover-to-date tracked
  against the exemption threshold.
- **For a VAT payer, revenue is the NET amount** — taxing gross would overstate
  a ryczałt base by 23%. Encoded and tested.

**Missing.**

| You asked for | Status |
|---|---|
| VAT sales | ✅ |
| VAT purchases | ⚠️ one monthly total, not a register |
| Deductible VAT | ⚠️ same |
| VAT payable | ✅ |
| **VAT by rate** | ⚠️ output yes (per sales line); **input no** — input VAT is a single figure with no rate split |
| Monthly summary | ✅ |
| **Quarterly summary** | ❌ **modelled but not implemented.** The profile carries `vat_settlement` and the report names the right structure, but **settlement runs monthly throughout.** A quarterly taxpayer would get the wrong period. |
| JPK_V7 preparation/checking | ❌ §10 |

Also missing: reverse charge, intra-EU acquisitions, split payment (MPP), the
bad-debt relief mechanism, and VAT-rate validation against the sales lines
actually used (a kebab shop typically has 5% / 8% / 23% in play, and nothing
checks that the register's letters map to rates that existed in that period).

**Data stored.** Output VAT, input VAT, amount to pay, carry-forward, the full
`Breakdown`, notes, and a `jpkStructure` **label**.

**Leaves the server.** No.

**An accountant expects.** A VAT sales register and a VAT purchase register,
both per rate, reconciling to JPK_V7, with the declaration part (V7M/V7K)
derived from them.

**Tests required.** Quarterly aggregation; input VAT per rate; a correction to
a past month flowing into the current declaration; carry-forward across a year
boundary; the exemption threshold crossed mid-year; 5% and 8% food rates
present in the rate table for the period.

---

### 10. JPK_V7 — **P0**

**Exists.** The *shape* of it: `Contracts\SchemaRegistry`,
`Contracts\SchemaVersion`, `Contracts\DocumentPreparer`,
`Contracts\PreparedDocument`, `pl_prepared_documents`, `PreparedDocumentModel`,
`SettlementRecorder::prepare()`, and `FilingChannel::JpkV7`.

**Works.** The refusals work. `SchemaRegistry::for()` throws when no version
covers the period, rather than falling back to the newest — the same refusal the
rate tables make, for the same reason. `PreparedDocument` distinguishes
"could not be validated" from "valid".

**Missing — the actual thing.**

- **No XSD is registered.** `SchemaRegistry` is constructed empty in
  `PolandServiceProvider`. `has()` returns false for every period.
- **No `DocumentPreparer` implementation exists anywhere in the codebase.** I
  searched: zero classes implement it. So no JPK_V7 XML can be generated, at
  all.
- No JPK_V7M or V7K structure builder, no ewidencja sprzedaży, no ewidencja
  zakupów, no GTU codes, no procedure markers (which a food business selling
  through a platform may well need), no validation against the schema, no
  export file.
- **No submission** — `UnconfiguredSubmitter` throws for every channel, and
  `FilingChannel::automated()` is `false` for all of them, pinned by a test.

**UI/doc only.** `jpkStructure` on `VatSettlement` is a **string label** naming
which structure *would* apply. Nothing produces it.

**Data stored.** `pl_prepared_documents` exists and can hold a prepared
document; nothing populates it.

**Leaves the server.** No. And this is deliberate — filing is a separate,
controlled deployment decision.

**An accountant expects.** A JPK_V7M file generated from the registers,
schema-validated, downloadable, and either submitted or handed to whoever
submits it. **This is a legal obligation for an active VAT taxpayer, monthly.**

**Tests required.** Schema version selected by period, not by "newest"; a
generated file validating against the real XSD; a correction (korekta) marked as
such with the right purpose code; totals in the declaration matching the
registers exactly; refusal when any rate table is unverified (the release gate
already enforces this for reports — extend it); a golden-file test so a schema
change is visible as a diff.

---

### 11. Income tax (PIT) — **P1**

**Exists.** `PitCalculator`, `PitInput`, `PitSettlement`, `PitRegime`,
`HealthDeduction`, `ContributionDeductionBasis`, versioned `config/rates/pit.php`,
`SettlementEngine::replayYear()`. Tested in `tests/Unit/PitCalculatorTest.php`
and `tests/Feature/HistoricalReproducibilityTest.php`.

**Works, and it gets the hard parts right.**

- **Advances are cumulative from 1 January** — `replayYear()` walks January
  forward and never settles a month in isolation. This is the mistake that
  produces a plausible wrong number, and it is handled.
- **Ryczałt taxes revenue; the scale and the flat tax tax income.** Encoded as a
  regime difference, not a special case.
- Multi-rate ryczałt is supported *by the engine* — `SalesLine` carries an
  optional per-line rate and deductions are apportioned proportionally.
- **Tax amounts and bases round to full złoty** (Ordynacja art. 63 §1) while
  contributions stay in grosze.
- `isEstimate` travels with the settlement, and an upper-bound result says so.

**Missing.**

- **Without a cost register, only ryczałt is exact.** For the scale and the flat
  tax the engine can only bound PIT from above — reported honestly, but it means
  those regimes are not usable for a real filing from cash-register data alone.
- **Multi-rate ryczałt has no data entry** — the engine supports several rates,
  the form accepts one. Fine for a single-activity business; **wrong for a kebab
  shop that also sells drinks or delivers**, which may fall under two rates.
- No annual return (§16), no loss carry-forward, no joint filing, no
  child/other reliefs, no IP Box, no PIT-11 handling.
- The rate table is **unverified** (§0 finding 3).

**Data stored.** Revenue/costs/deductions/base/tax year-to-date, advances
already due, the advance due for the month, breakdown, notes, estimate flag.

**Leaves the server.** No.

**An accountant expects.** Monthly advances from a KPiR or a ryczałt revenue
register, an annual return, and the ability to re-derive any month's figure.

**Tests required.** The multi-rate ryczałt path exercised end to end through
data entry, not just the calculator; a mid-year regime change; a correction to
an early month re-flowing through every later advance; the ryczałt health band
crossing mid-year; a loss month under the scale.

---

### 12. ZUS — **P2 (the most complete area)**

**Exists.** `ZusCalculator`, `ZusSettlement`, `ZusScheme`,
`config/rates/zus_social.php`, `config/rates/zus_health.php`,
`pl_payment_obligations`, `DeadlineCalendar`, and the obligations section of the
report and dashboard. Tested in `tests/Unit/ZusCalculatorTest.php`.

**Works, including four rules that are easy to get wrong:**

1. **The health year runs 1 February – 31 January.** January is settled on the
   *previous* year's figures. The rate table is dated by month so this comes out
   right without anybody remembering it.
2. **The health contribution looks back** — under the scale and the flat tax it
   is 9% / 4.9% of the income of the month *before* the settled one (art. 81
   ust. 2), and the class states which month it used.
3. **Fundusz Pracy is due only from a base at or above the minimum wage** —
   encoded as the rule, which is why the preferential scheme does not pay it,
   rather than as a per-scheme exception.
4. **An incomplete first month prorates social contributions by days, but the
   health contribution is indivisible** and is paid whole.

Obligations carry kind, form, pay-to, amount, due date, a
`due_date_verified` flag, status, surplus, paid-at, amount paid, payment
reference and notes — with **deadlines moved off weekends and holidays, later
only**, and the report saying the shift *could not be applied* when the holiday
calendar has no entry for the year.

**Missing.**

- Voluntary sickness insurance is a **flag**, not a schedule — you cannot record
  joining or leaving mid-year.
- **Suspension and sickness do not shorten a month.** Only an incomplete *first*
  month prorates. A business suspension or a sick-leave period also reduces the
  base; the engine is not told about them and computes a full month, stating
  that assumption.
- Mały ZUS Plus base is **supplied, not derived**, and the 36-in-60-months
  entitlement is not tracked.
- **No annual health reconciliation** (§16) — crossing a ryczałt band mid-year
  creates a year-end top-up; the report warns, it does not compute.
- No DRA generation, no ZUS submission, no e-Składka payment identifier.
- **The public holiday calendar ends in 2027.**

**Data stored.** Contribution base, social components, social total, health,
total, breakdown, notes, health basis; obligations as above.

**Leaves the server.** No.

**An accountant expects.** Monthly contributions with the DRA, payment
reference, deadlines, and the annual health settlement.

**Tests required.** Suspension shortening a month; sickness reducing the base;
a scheme change mid-year (ulga na start → preferential → full); the 24-month
preferential window expiring; Mały ZUS Plus entitlement exhausted; holiday
calendar extended past 2027.

---

### 13. Employees / payroll — **P1**

**Exists.** **Nothing.** No table, no model, no calculator, no enum, no test. A
repository-wide search for payroll/employee terms returns two incidental
matches (a deadline label and a review-flag string).

**Works.** n/a.

**Missing.** All of it: employee register, contract types (umowa o pracę,
zlecenie, dzieło), gross-to-net, employee and employer ZUS, PIT-4R advances,
PPK, holiday entitlement, sick pay, PIT-11, and the ZUS DRA extension for
employees. Employee cost also has no representation on the cost side (§8).

**Data stored.** None.

**Leaves the server.** No.

**An accountant expects.** If the shop employs anyone — and a kebab shop
usually does — this is a legal monthly obligation with its own deadlines and its
own penalties.

**Priority.** **P1 if anyone is employed, P3 if the owner works alone.** This is
a question to answer before planning, not a technical decision: it is the
largest single missing area and it changes the shape of the roadmap.

**Tests required.** Everything, from scratch.

---

### 14. KSeF — **P1**

Fully documented in `docs/KSEF_USER_GUIDE.md` (customer-facing) and
`docs/OPEN_ITEMS.md`. In summary:

**Exists and works.** The FA invoice parser (FA(2) and forward-compatible
namespace handling), duplicate prevention by unique index, the incremental sync
cursor that never advances on a failed run, immutable original XML and KSeF
number, encrypted `InvoiceRead`-only credentials with a single greppable read
path, REQUIRES REVIEW propagation to already-generated reports, and status
wording that can never read as "no invoices". Covered by
`CredentialSecurityTest` (10 properties), `SyncSafetyTest`,
`IntegrationStatusTest`, `FaInvoiceParserTest`.

**Missing.** The HTTP transport — `UnconfiguredKsefClient` refuses. And any user
interface: no KSeF page, no wizard, no token form, no test-connection button,
and **no way to trigger a sync** (`MonthCloseService` has no caller).

**Deliberately absent.** Invoice issuing and submission. `InvoiceRead` only, and
`InvoiceWrite` exists in the enum precisely so it can be refused by name.

**Note on the obligation.** You are right that receiving through KSeF is the
part that lands first and issuing phases in. That ordering matches this
codebase's design — read first, and issuing only as a separate deliberate
decision. **The exact dates and which stage applies to a JDG of this size must
be verified against the Ministry's current publication**, not from this
repository and not from memory; the same rule the rate tables are under.

**Leaves the server.** Only KSeF, only when enabled, only `InvoiceRead`.

**Tests required.** Contract tests against the KSeF test environment; auth
failure carrying no credential material; token expiry mid-sync; a page cursor
that never advances on failure (already tested at the unit level, needs an
integration test); a correction invoice superseding an original.

---

### 15. Reports — **P2 (genuinely good)**

**Exists.** `AccountantReportBuilder`, `AccountantReport`, `MonthlyTaxReport`,
`TextReportRenderer`, `Breakdown`, `Line`, `FinancialResult`,
`pl_report_versions`, `MonthlyReportService`, `ReportController`, and
`report.blade.php` + `history.blade.php`. Tested in
`tests/Feature/AccountantReportTest.php` and `HistoricalReproducibilityTest.php`.

**Works, and this is the part that would impress an accountant.**

- **Versioned, checksummed, immutable.** Every generation is a new version with
  a checksum and `rate_provenance` frozen alongside it.
- **Provenance on every figure** — source, rate-table version, period, formula,
  legal basis. A figure the taxpayer cannot re-derive is a figure taken on faith.
- **Certainty labels** — `VERIFIED` / `CALCULATED` / `REQUIRES REVIEW` /
  `NOT ENOUGH DATA` / `BLOCKED` / `FAILED`, each meaning something different and
  each tested.
- **Close and reopen** with a reason recorded (`MonthCloseService`,
  `is_closed`, `reopen_reason`).
- **`isEstimate`** and warnings when a result is bounded rather than exact.
- **A calculation is never presented as a filing** — `DISCLAIMER` is on every
  report and `filed_at` is set only by a submission that returned a reference.
- **Historical reproducibility is tested**: regenerating an old month uses the
  rate version that applied then.

**Missing.** No PDF or CSV export (§20); no annual report (§16); no
profit-and-loss over time; no comparison between months; no accountant-oriented
export in any interchange format.

**Data stored.** Full report JSON, rate provenance, checksum, total due,
estimate and fit-for-filing flags, generated by/at, closed state.

**Leaves the server.** No.

**Tests required.** Export byte-for-byte stable for the same version; a report
regenerated after a rate correction producing a new version, never mutating the
old one.

---

### 16. Year-end — **P1**

**Exists.** Nothing specific. `SettlementEngine::replayYear()` walks a year for
monthly advances, which is the foundation, but there is no annual close.

**Missing.**

- **No annual return.** No PIT-28 (ryczałt), no PIT-36, no PIT-36L. Not modelled,
  not built.
- **No annual health-contribution reconciliation.** Crossing a ryczałt revenue
  band mid-year creates a year-end top-up or refund. **The report warns; it does
  not calculate.**
- No year-end closing, no carry-forward of a VAT surplus or a loss across the
  year boundary as an explicit act, no annual summary, no fixed-asset roll-over,
  no stocktake (which a food business needs and which shop-intelligence models
  but does not persist).
- **The holiday calendar ends in 2027**, so deadlines beyond it come back with
  "the working-day shift could not be applied".

**Data stored.** Nothing annual.

**Leaves the server.** No.

**An accountant expects.** The annual return is the point of the whole year's
bookkeeping. Monthly advances without it are unfinished work.

**Tests required.** Annual reconciliation matching the sum of monthly advances;
the ryczałt band crossing producing the right top-up; a correction to an early
month changing the annual figure; a year with a regime change.

---

### 17. Document storage — **P0**

**Exists.** `pl_government_documents` with `stored_path`, `checksum`,
`filename`, `uploaded_by`, `received_at`, and a unique
`(tax_profile_id, checksum)`. `pl_ksef_documents.original_xml` stores invoice
XML **in the database** as `longText`, immutable, with a SHA-256 checksum and
`verifyXmlChecksum()`.

**Works.** The KSeF XML custody model is correct: immutable, checksummed,
verifiable, never edited. Government documents are stored, checksummed, and
marked **needs manual review** rather than silently classified — because
`UnavailableTextExtractor` refuses and **missing OCR is not "no text"**.

**Missing.**

- **No upload route, for anything.** Not for a purchase invoice, not for a
  receipt, not for a bank statement, not for a government letter. `stored_path`
  is populated by nothing.
- **No storage for purchase invoices or receipts at all** — the only invoice
  storage is the KSeF table, and only for downloaded invoices.
- No storage configuration (local disk vs anything else), no retention policy,
  **no encryption at rest for stored files** (unlike the KSeF token, which is
  encrypted), no virus scanning, no size or type limits, no thumbnailing or
  preview.
- **No OCR / PDF text extraction.** No `tesseract`, no `pdftotext`. This also
  blocks any "photograph the receipt" workflow.

**Data stored.** For government documents: filename, path, checksum, authority,
action, type, dates, case number, amounts, deadlines, required action, extracted
text and JSON, review flags, status, resolution. For KSeF: the XML itself.

**Leaves the server.** No — no cloud storage, no external document processing.

**An accountant expects.** Every source document retained and retrievable for
**five years from the end of the year of filing**, linked to its ledger entry.

**Tests required.** Upload authorisation, type and size limits; checksum
deduplication; a stored file surviving a backup/restore cycle; retention
enforced; a document linked to an invoice not deletable while the invoice
stands; path traversal rejected.

---

### 18. Audit trail — **P2 (strong)**

**Exists.** `pl_audit_events`, `AuditEventModel`, `AuditRecorder`.

**Works.**

- **Append-only enforced at the model level** — `updating` and `deleting` both
  throw. Not a convention; a mechanism.
- **Actor captured automatically** — id, label and IP, resolved inside
  `AuditRecorder::record()`, so a call site cannot forget it.
- Old value and new value as JSON, plus period, subject type and id, result,
  error, source (`web` / `ksef` / …), and an indexed `occurred_at`.
- Named actions including `ksef.synced`, `ksef.invoice_imported`,
  `report.requires_review`.

**Missing.** No hash chaining (a database administrator could still delete a row
directly — the model guard protects the application, not the DBA); no
retention/archival policy; **no way to view the audit trail** — no route, no
screen, no export; no alerting on suspicious patterns.

**Data stored.** As above. Note `actor_ip` is personal data under GDPR and has
no stated retention period.

**Leaves the server.** No.

**Tests required.** Update and delete refused (likely covered — verify);
every write path producing an event; actor recorded for CLI and web; an
append-only guarantee test at the database level, not only the model level.

---

### 19. Backups — **P0**

**Exists.** `deploy/backup.sh` (described as a *verified* backup script),
`bin/data-safety-drill.sh` (backup, restore, and prove the records survived),
and `docs/DEPLOYMENT.md`.

**Works.** Unknown. Both are **written and syntax-checked and neither has ever
restored anything**, because the build environment has no database server.

**This is the definition of an untested backup, and an untested backup is not a
backup.**

**Missing.** A real drill on the Contabo box (it must exit 0); off-site copies;
encryption of backups (they contain the encrypted KSeF token *and* the
application key is what decrypts it — losing both together loses everything, and
storing them together is worse); a documented restore-time objective; monitoring
that a backup actually ran; and stored documents (§17) included in the backup
set, not just the database.

**Leaves the server.** Depends on where backups go — **undecided, and it is a
privacy decision**: an off-site backup is accounting data leaving the server, and
it should be an explicit, encrypted, named destination rather than a default.

**Tests required.** The drill itself, run for real; a restore into a clean
database producing byte-identical report checksums; a restore with the
application key rotated failing loudly rather than silently losing the KSeF
token.

---

### 20. Privacy and data export — **P1**

**Privacy: see `docs/DATA_PRIVACY_AND_EXTERNAL_CONNECTIONS.md`.** Summary: no
accounting data leaves the server, no HTTP client is installed, mail goes to a
log, there is no analytics and no AI provider. **True today, but not enforced by
any mechanism** — there is no privacy equivalent of `bin/check-isolation.sh`.

**Export: there is none.**

- No CSV, no PDF, no XML, no JSON export route.
- No JPK_V7 file (§10).
- No accountant handover package.
- **No GDPR data export or erasure path** — and the audit trail is append-only
  by design, which is correct for accounting and a real tension with erasure
  that should be *documented* rather than discovered later.
- `TextReportRenderer` produces text and is used by the CLI; the web has no
  download.

**An accountant expects.** To be handed the year's records in a form they can
import — and the taxpayer to be able to take their data elsewhere.

**Tests required.** Export completeness (every stored figure present); export
determinism; export authorisation; a privacy guard script failing the build when
an outbound primitive appears.

---

## Part 2 — What to do about it

### The order I would actually build in

**Stage 0 — unblock, before any feature.**
1. Verify the rate tables (`php artisan poland:rate-provenance --todo`) —
   nothing produced by this system may be paid until this is done.
2. Run `bin/data-safety-drill.sh` on the real box until it exits 0.
3. Answer two questions that change the design: **does the shop employ
   anyone**, and **what are the actual PKD activities and ryczałt rate(s)**.

**Stage 1 — a way in (largest gap, smallest concepts).**
Taxpayer profile form → bank statement upload → purchase invoice entry.
`BankImportService` already works; it needs a route and a form.

**Stage 2 — the daily grain.**
Schema change: daily sales with channels (cash / card / Glovo / other), Z-report
records with cash counted vs expected, purchase invoices as individual documents
with categories and suppliers. **This is the change everything else waits on** —
do it before building screens over the monthly model.

**Stage 3 — Glovo as a first-class document** in the accounting module, with the
commission's VAT split out.

**Stage 4 — the VAT registers and JPK_V7**, since the registers are what JPK is
generated from.

**Stage 5 — year-end**: annual return and the annual health reconciliation.

**Stage 6 — KSeF transport (read)**, against the current official API, tested in
the KSeF test environment first.

Your six-phase plan and this order agree; the difference is that **Stage 0 and
Stage 2 come before all of it**, and the "private bookkeeping" phase is mostly a
schema change rather than a UI one.

### Two things not to do

1. **Do not build the daily/Glovo features inside `shop-intelligence/`.** It has
   no path to a tax return by design — no shared database, no shared table, no
   code path — and that isolation is deliberate and tested. Numbers that must
   reach a VAT return belong in the accounting module.
2. **Do not build screens over the monthly schema** and migrate later. Every
   screen built on `pl_sales_reports` as it stands has to be rewritten when the
   daily grain arrives.

### Cross-cutting tests to add regardless of feature

- **A route-coverage test**: every registered service reachable from a route or
  a command, so "built but unreachable" (Finding 1) cannot recur silently.
- **A privacy guard**: `bin/check-privacy.sh` alongside the isolation guard.
- **A schema/model consistency test**: every `$table` on a model has a migration
  (checked by hand here — all fifteen do).
- **An authorisation test per route**: today all nine sit behind `web` + `auth`
  with no per-profile ownership check. **A logged-in user can read any taxpayer
  profile by passing `?profile=<id>`.** With one taxpayer that is theoretical;
  it stops being theoretical the moment there are two.

---

## Part 3 — Test suite result

Run at audit time, unchanged code:

```
modules/poland/vendor/bin/phpunit
PHPUnit 11.5.56 · PHP 8.4.19

OK (309 tests, 874 assertions)
```

```
shop-intelligence: ../modules/poland/vendor/bin/phpunit -c phpunit.xml
OK (60 tests, 469 assertions)
```

```
bin/check-isolation.sh
  → 4 checks, "Izolacja od systemu tradingowego zachowana."  (exit 0)

shop-intelligence/bin/check-shop-isolation.sh
  → "ISOLATION PROVEN — 10 checks, 0 violations."            (exit 0)
```

**Total: 369 tests, 1343 assertions, 0 failures.** The tests that exist pass.
What this audit measures is what they do not cover — and per Finding 1, a large
part of what they *do* cover is not reachable by a user.

---

*Audit performed 2026-09-09 against the source at that date. No routes,
controllers, models, migrations, views, services or schema were modified.
Findings are recorded in `docs/OPEN_ITEMS.md`.*
