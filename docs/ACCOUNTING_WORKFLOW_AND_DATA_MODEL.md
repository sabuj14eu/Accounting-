# Accounting workflow and data model — the kebab shop

**Status: design of record; stage B implemented on 2026-09-10** (see
`docs/CHANGELOG.md`, entry "2026-09-10 (second)"). Stages C–F of section 15
are not built. No KSeF connection or token exists. This document records the
**change of direction** decided by the owner on 2026-09-10 and the
architecture that follows from it; where the implementation refined a
detail, the section says so (3.3).

This document supersedes the earlier proposal for a mandatory daily-sales
schema. That proposal is **cancelled** (section 8 of the owner's instruction).
Nothing here creates a daily grain.

---

## 0. The direction, in one paragraph

One person runs the shop. The accounting application must therefore do the
work that a bookkeeper would otherwise do every evening, without asking the
owner to type invoices, upload PDFs or record each day's takings. Supplier
invoices arrive **automatically from KSeF** when the authorised connection is
enabled; the owner **approves** them; the application books the purchase and
its VAT and, **only for products the owner has chosen to track**, moves stock.
Sales are entered **once a month** as two figures, shop and Glovo. Glovo's
statement is an **accounting settlement source** reconciled against the bank.
From those inputs the deterministic engine produces VAT, PIT and ZUS for the
month, and later the JPK_V7 preparation. Everything stays on the owner's
server; the only planned external connection is KSeF, and it is off until it
is explicitly configured and enabled.

The laws in `CLAUDE.md` are unchanged and every one of them applies to what is
designed below. Three of them decide most of the shape: **a calculation is not
a filing**; **absence is not zero**; **an issued document is never silently
rewritten.**

---

## 1. Exact real-life workflow for the kebab shop

What actually happens, in the order it happens, with the application's role at
each step. "Automatic" means no owner action. "One tap" means the owner sees
something and confirms it.

### Every day (automatic, owner does nothing)

| When | What happens | Application role |
|---|---|---|
| A supplier delivers drinks, meat, bread, vegetables | The supplier issues a VAT invoice to the shop's NIP. Since the e-invoicing obligation, that invoice goes into KSeF | Nothing yet — the invoice is in the Ministry's system |
| Several times a day (scheduler) | The application asks KSeF for invoices received since the last confirmed mark | **Automatic**, when the KSeF integration is enabled and the transport exists (section 19). Until then this step reports NOT CONNECTED, never "no invoices" |
| An invoice arrives | The original XML is stored verbatim, parsed, and placed in the **review inbox** | **Automatic** |
| The inbox has items | The owner opens the inbox on the phone, sees supplier, amount, lines, and taps **Approve** (or Reject, or "map this product") | **One tap** per invoice. Later, trusted-supplier auto-approval can be switched on per supplier — explicitly, section 3.4 |
| An invoice is approved | Purchase and input VAT are posted to the month. Lines for **tracked** products create stock movements; lines for untracked products do not | **Automatic** |

### What does NOT arrive automatically, said plainly

KSeF carries structured invoices between VAT taxpayers. It does **not** carry:

- ordinary till receipts (paragony) from a cash-and-carry when the owner did
  not ask for an invoice to the shop's NIP;
- simplified invoices (paragon z NIP up to 450 zł) — excluded from KSeF;
- foreign suppliers' invoices;
- Glovo's own settlement statement (only Glovo's *invoice* for its commission
  would be in KSeF, and only if the issuing entity is a Polish VAT taxpayer).

So the practical rule for a fully automatic month is: **at the wholesaler,
always ask for a full VAT invoice to the shop's NIP.** It then arrives by itself.
Anything bought without one is invisible to the system, and the month's report
will say "documents outside KSeF are not seen", never "no other costs". A
manual "other document" path stays possible but is **optional and never
required** (section 15).

### Once a month (owner, about ten minutes)

| Step | Owner does | Application does |
|---|---|---|
| 1 | Prints the cash register's monthly report (raport miesięczny) and enters the gross total — per rate letter if the register prints them | Records a **monthly sales report** for the shop channel (exists today) |
| 2 | Enters the Glovo month: gross orders, commission, other deductions, payout received — or imports Glovo's statement file when that importer exists | Records a **platform settlement** and its VAT treatment; the gross orders become the Glovo sales channel of the same month |
| 3 | Imports the bank statement (CSV/MT940/camt.053 — exists today) | Matches supplier payments to approved invoices, the Glovo payout to the settlement, ZUS and tax transfers to obligations |
| 4 | Clears the review queue: possible duplicates, unmatched transfers, unmapped products | Everything unresolved stays REQUIRES REVIEW and is excluded, never booked |
| 5 | Opens the monthly report | VAT (output from sales, input from approved invoices), PIT advance, ZUS, payment checklist with deadlines, certainty and caveats; later, the JPK_V7 preparation |
| 6 | Pays ZUS / VAT / PIT from the bank and records the reference, or the next bank import matches them | Payment checklist turns PAID; the month can be closed |

### Occasionally

- Enable stock tracking for a new product (one toggle).
- Count the tracked drinks on the shelf and enter the counts (optional, any
  time; the system never demands it).
- Approve a correction invoice (faktura korygująca), which reverses the
  original booking and posts the corrected one.

---

## 2. Automatic KSeF incoming-invoice flow

```
Supplier issues FA(x) invoice to the shop's NIP
        │
        ▼
KSeF assigns a KSeF number and a permanent-storage timestamp
        │  (for the BUYER this timestamp is the date of receipt)
        ▼
[scheduler, N times a day]  KsefIngestService::sync(profile, window)
        │   InvoiceRead scope only · window = last confirmed mark → now
        │   page → validate → persist → commit → advance cursor
        ▼
pl_ksef_documents (original XML, checksum, parsed header, direction)
        │   unique (tax_profile_id, ksef_number)        ← duplicate wall
        │   processing_status = imported | needs_review
        ▼
LINE PARSING (new)   →  pl_purchase_invoice_lines
        │   FaWiersz rows: description, qty, unit, net, VAT rate, gross
        │   product mapping via pl_product_aliases (supplier NIP + text)
        ▼
REVIEW INBOX (new)   →  approval_status = awaiting_review
        │   owner: Approve · Reject · Map product · Mark not ours
        ▼
APPROVE  →  pl_purchase_postings (accounting + VAT, one per invoice)
         →  pl_inventory_movements (only for lines whose product is tracked)
         →  pl_audit_events (who approved, when, what was posted)
```

### 2.1 What exists and is executed today

`KsefIngestService`, `FaInvoiceParser`, `SyncCursor`, `TransportGate`,
`KsefCredentialModel` (encrypted, InvoiceRead-only, redacted everywhere),
`pl_ksef_documents` / `pl_ksef_sync_state` / `pl_ksef_credentials`, the unique
index on the KSeF number, immutable `original_xml`, and REQUIRES REVIEW
propagation to already-generated reports. All tested; recorded in
`docs/PRODUCTION_AUDIT_2026-09-07.md`.

### 2.2 What the transport must do when it is built (separate stage)

The `KsefClient` port already fixes the contract: `openSession`,
`queryInvoices(from, to, cursor)`, `fetchInvoiceXml(ksefNumber)`,
`closeSession`. The real implementation is written **only** against the
current official API specification, tested first against the KSeF **test**
environment, and enabled by `KSEF_TRANSPORT=real` + `KSEF_TRANSPORT_ENABLED=true`
+ a token the owner generates in KSeF with **InvoiceRead** only. The 12-step
go-live checklist in the production audit (§1) is the acceptance test. Nothing
about endpoints, authentication method or schema version may be hard-coded;
the Ministry has changed all three.

**Retrieval window and cadence.** Routine sync looks back `lookback_days`
(config, default 45) so a server that was down for a week still catches up.
Cadence is a scheduler decision (e.g. every 2 hours during the day); the cursor
rule makes frequency safe — a rerun of a window produces duplicates that the
index rejects, never gaps.

**Direction.** An invoice whose `Podmiot2` NIP is the shop's is incoming. An
invoice where the shop is `Podmiot1` is outgoing (the shop issued it — rare for
a kebab shop, but a catering invoice to a company is one) and is filed under
sales, not purchases.

### 2.3 Invoice data captured

The owner's list, mapped to where each item lives. **Exists** = column present
in `pl_ksef_documents` today. **Add** = additive column or new table (section 4).

| Required datum | FA element (FA(2) local names; verify against the current XSD) | Where |
|---|---|---|
| supplier | `Podmiot1/DaneIdentyfikacyjne/Nazwa` | exists `seller_name` |
| supplier NIP | `Podmiot1/DaneIdentyfikacyjne/NIP` | exists `seller_nip` |
| invoice number | `Fa/P_2` | exists `invoice_number` |
| invoice date | `Fa/P_1` | exists `invoice_date` |
| sale / service date | `Fa/P_6` (or `OkresFa` for a period) | exists `sale_date`; **add** `service_period_from/to` |
| due date | `Fa/Platnosc/TerminPlatnosci/Termin` | **add** `due_date` |
| invoice type | `Fa/RodzajFaktury` (VAT, KOR, ZAL, ROZ, UPR…) | exists `invoice_type` |
| line items | `Fa/FaWiersz` (repeating) | **add** table `pl_purchase_invoice_lines` |
| product / service description | `FaWiersz/P_7` (+ `Indeks`, `GTIN`, `PKWiU`) | line `description`, `supplier_index`, `gtin` |
| quantity | `FaWiersz/P_8B` | line `quantity` |
| unit | `FaWiersz/P_8A` | line `unit` |
| net amount | `FaWiersz/P_11`; totals `Fa/P_13_x` | line `net`; header `net` exists |
| VAT rate | `FaWiersz/P_12` | line `vat_rate` |
| VAT amount | `FaWiersz/P_11Vat` where present, else **derived and labelled derived**; totals `Fa/P_14_x` | line `vat`, `vat_is_derived` |
| gross amount | `FaWiersz/P_11A` where present; `Fa/P_15` | line `gross`; header `gross` exists |
| payment information | `Fa/Platnosc/*`: `Zaplacono`, `DataZaplaty`, `FormaPlatnosci`, `RachunekBankowy/NrRB` | **add** `payment_form`, `paid_on_invoice`, `supplier_account`, `payment_terms_json` |
| KSeF invoice identifier | KSeF number from metadata | exists `ksef_number` (immutable) |
| source / system | — | **add** `source` (`ksef` · later `manual`), plus `Naglowek/SystemInfo` kept in `metadata` |
| import timestamp | — | exists `retrieved_at`; `permanent_storage_date` = receipt date |
| approval status | — | **add** `approval_status`, `approved_by`, `approved_at`, `decision_note` |
| original electronic data for audit | whole document | exists `original_xml` + `xml_checksum`, immutable at the model; `metadata` and `parse_result` JSON kept whole; unknown elements listed |

The parser is namespace-agnostic and records unknown elements, so a schema
version that adds a field shows up as "unmapped", not as a wrong number.
**Missing stays `MISSING_FIELD`, never 0,00.**

---

## 3. Invoice review and approval process

### 3.1 States

`pl_ksef_documents.approval_status` (new column; `processing_status` keeps its
present technical meaning):

```
imported ──▶ awaiting_review ──▶ approved ──▶ posted
                 │                                │
                 ├──▶ rejected (not ours / disputed / duplicate of another number)
                 │
                 └──▶ superseded (a correction invoice replaced it — original stays)
```

- **imported → awaiting_review** is automatic and immediate. Every incoming
  invoice enters review at least once, including from a trusted supplier, until
  a rule (3.4) says otherwise.
- **approved → posted** happens in one database transaction with the posting
  and the stock movements. A failure anywhere rolls back everything; the
  invoice stays `approved` and the failure is a `Caveat::operationFailed`
  (FAILED, not "no cost").
- **rejected** never deletes. The XML stays; the reason is required; the
  invoice is excluded from every register; an audit event names who rejected
  it. A rejected invoice that the supplier later corrects arrives as a KOR and
  is handled by section 3.3.
- Nothing ever goes backwards silently. Un-posting is a **reversal posting**
  with a reason (section 4.3), never an edit.

### 3.2 What the owner sees per invoice

Supplier, NIP (with a first-time-supplier badge), invoice number and date,
due date, gross, net, VAT by rate, **each line** with quantity, unit, net,
rate — and next to each line either the mapped product (with its TRACK / NOT
TRACKED chip) or **"unmapped — accounting only"**. Below: what approving will
do, in words:

> Posts 1 200,00 zł net + 276,00 zł VAT (23%) and 64,00 zł net + 3,20 zł VAT
> (5%) to August 2026. Increases stock: Coca-Cola 0,5 l +240 szt (10 op. × 24).
> No stock change for: cebula 20 kg (not tracked).

An approval is always a decision about **this** wording. If the parse is
incomplete, totals disagree, or it is a correction, the Approve button is
replaced by the reason and the action that clears it.

### 3.3 Corrections (faktura korygująca)

A KOR carries `DaneFaKorygowanej` with the original's number and, where the
original was in KSeF, its KSeF number. The design:

1. Link the KOR to the original by KSeF number first, invoice number + seller
   NIP second. No link found → REQUIRES REVIEW, the owner picks the original or
   confirms there is none in this system.
2. **Implemented (stage B) as a signed adjustment.** In the FA schema a
   correction's amounts are DIFFERENCES, so approving a KOR posts its own
   signed posting (negative for an in-minus correction) linked to the original
   through `adjusts_posting_id`, in the KOR's own VAT period as the law
   requires for the buyer. Its lines' signed quantities move stock for tracked
   products. The original posting and document stay exactly as they were;
   nothing is deleted or edited. This replaces the earlier "reverse and
   re-post" wording: a difference document does not need the original undone.
3. A KOR that cannot be linked to an original in this system is REQUIRES
   REVIEW until the owner picks the original or confirms there is none. A KOR
   the parser cannot read as signed differences (missing amounts, unknown
   rate) is blocked by the same gate as any other document.

### 3.4 Trusted-supplier auto-approval (later, explicit, per supplier)

For a one-person shop, approving the same drinks wholesaler's invoice every
week is ceremony. So the design allows an **auto-approval rule per supplier
NIP**, off by default, that approves an invoice automatically only when **all**
of these hold:

- parse complete, totals agree, not a correction, currency PLN;
- every line maps to a known product or alias (no unmapped line);
- gross does not exceed the rule's ceiling (owner-set);
- the supplier has at least N (owner-set, suggested 5) manually approved
  invoices with no rejection;
- the rule is enabled and was enabled by a logged owner action.

Each auto-approval is audited as `actor = system:auto-approve, rule = #id` and
appears in a daily digest the owner can glance at. Any failure of any condition
drops the invoice back to `awaiting_review`. This is the same posture as the
transaction matcher: an exact, checkable rule may act; anything plausible asks.

### 3.5 Who may approve

Only an authenticated user of the accounting application. No interpreter, no
AI, no importer. `InterpretationBoundary` already forbids an interpretation from
setting engine fields; the approval action is added to the forbidden list by
the same mechanism (a suggestion may propose a product mapping; it may never
set `approval_status`).

---

## 4. Accounting data model

Everything new is `pl_`-prefixed and additive. Existing tables are extended
with nullable columns only. Money stays `decimal(14,2)` in the database and
integer grosze in the domain. Nothing below is a migration; it is the
specification a migration will be written from, each with its own note in
`docs/CHANGELOG.md`.

### 4.1 Document layer (source of truth, immutable)

**`pl_ksef_documents`** — exists. Additive columns:

| Column | Type | Meaning |
|---|---|---|
| `due_date` | date null | from `Platnosc/TerminPlatnosci` |
| `service_period_from`, `service_period_to` | date null | from `OkresFa` |
| `payment_form` | string(20) null | FA `FormaPlatnosci` code, stored as the code plus its label |
| `paid_on_invoice` | bool null | `Zaplacono`; null when the element is absent (absence is not "unpaid") |
| `supplier_account` | string(40) null | `RachunekBankowy/NrRB`, used by the bank matcher |
| `payment_terms_json` | json null | the whole `Platnosc` node, kept |
| `source` | string(20) default `ksef` | `ksef` now; `manual` is a later, optional path |
| `approval_status` | string(20) default `imported` | section 3.1 |
| `approved_by`, `approved_at`, `decision_note` | string / timestamp / text, null | who, when, why |
| `corrects_document_id` | fk self null | a KOR → its original |
| `possible_duplicate_of` | fk self null | section 12 |

**`pl_purchase_invoice_lines`** — new.

| Column | Meaning |
|---|---|
| `ksef_document_id` fk | parent |
| `line_no` | `NrWierszaFa` |
| `description` | `P_7` verbatim |
| `supplier_index`, `gtin`, `pkwiu` | when present |
| `quantity` decimal(14,3) null, `unit` string(20) null | `P_8B`, `P_8A`; null when absent |
| `unit_net_price` decimal(14,4) null | `P_9A` |
| `net` decimal(14,2) null, `vat_rate` string(6), `vat` decimal(14,2) null, `vat_is_derived` bool, `gross` decimal(14,2) null | `P_11`, `P_12`, `P_11Vat`/derived, `P_11A` |
| `gtu`, `procedure` | when present (needed for JPK) |
| `product_id` fk null | resolved mapping; null = accounting only |
| `mapping_source` | `alias` · `owner` · `none` |
| `raw` json | the whole `FaWiersz` node |
| unique (`ksef_document_id`, `line_no`) | |

Rule: **line totals are checked against header totals per rate.** A mismatch is
REQUIRES REVIEW; the header (what the issuer declared and KSeF accepted) is
never silently "fixed" to match the lines or vice versa.

### 4.2 Master data

**`pl_suppliers`** — new. One row per seller NIP seen: `nip` (unique per
profile), `name` as last seen, `first_seen_at`, `invoice_count`,
`auto_approve_rule_id` null. Derived from documents; never typed.

**`pl_products`** — new. `code`, `name`, `category` (free text with a small
suggested list: DRINKS, MEAT, BREAD, VEGETABLES, SAUCES, PACKAGING, OTHER),
`stock_unit` (`szt` · `kg` · `l` · `op`), **`inventory_tracked` bool default
false**, `tracking_changed_at`, `default_cost_category` (section 9), `active`.

**`pl_product_aliases`** — new. How a supplier names the product: (`supplier_nip`,
`normalised_description`, optional `supplier_index`/`gtin`) → `product_id`,
plus `pack_size` (e.g. 1 `op` = 24 `szt`) and `pack_unit`. Created the first
time the owner maps a line; used automatically afterwards. Unique on
(`supplier_nip`, `normalised_description`, `supplier_index`).

### 4.3 Booking layer (derived, append-only, reversible)

**`pl_purchase_postings`** — new. One per approved invoice.

| Column | Meaning |
|---|---|
| `ksef_document_id` fk, **unique** | the idempotency wall: one posting per document, enforced by the database |
| `booking_period` string(7) | the month the cost belongs to (invoice date month by default) |
| `vat_period` string(7) | the month the input VAT is deducted in — section 8.3 |
| `deductible_net`, `deductible_input_vat` | what goes to the registers |
| `non_deductible_net`, `non_deductible_vat` | e.g. representation costs, 50% on a car; owner decision at review, default 100% deductible for goods for resale |
| `by_rate` json | `{ "23": {net, vat}, "8": {…}, "5": {…}, "zw": {…} }` from lines |
| `cost_category` | section 9 (KPiR column mapping) |
| `status` | `posted` · `reversed` |
| `reverses_posting_id` fk null, `reversed_by_posting_id` fk null | correction chain |
| `posted_by`, `posted_at`, `reason` | actor and audit |

**A posting is never updated except to set `reversed_by_posting_id`.** Any
change of amount, period or category is a reversal plus a new posting with a
reason, both kept.

**Monthly purchase register (read model).** The domain object
`PurchaseRegister(period, deductibleCostsNet, deductibleInputVat, documentCount)`
that the engine already consumes is **built by summing postings** for the
period. `pl_purchase_summaries` (the manual monthly totals typed in today) stays
as a legacy/manual source. Rule for a month that has both: **they are never
added together**; the month is REQUIRES REVIEW until the owner marks the manual
summary superseded. A month with postings and no manual summary uses postings.
A month with neither remains "no purchase register" — an upper bound, as today.

### 4.4 Sales layer

**`pl_sales_reports` + `pl_sales_report_lines`** — exist; monthly; corrections
supersede. Additive columns on lines: `channel` (`shop_register` · `glovo` ·
`other`, default `shop_register`), `source_type`/`source_id` (a Glovo line
points at its `pl_platform_settlements` row). Section 6.

### 4.5 Platform layer

**`pl_platform_settlements`** — new. Section 7.

### 4.6 Inventory layer (optional)

**`pl_inventory_movements`**, **`pl_inventory_counts`** — new. Section 5.

### 4.7 Relationship to the Liberu general ledger

The Poland module keeps its own registers (`pl_*`) and the tax engine reads
only them. Posting to the foundation's double-entry ledger (its chart of
accounts, journals) is **not** in this design: a JDG on a cash register with
ryczałt or KPiR does not keep full books (księgi rachunkowe), and mirroring
into Liberu's GL would create a second source of truth that could disagree with
the registers the tax is computed from. If full books are ever required, that
is a separate design with the Polish chart of accounts, listed in
`docs/OPEN_ITEMS.md` as P1 today.

---

## 5. Optional inventory model

### 5.1 What "optional" means in the schema

`pl_products.inventory_tracked` is **false by default**. The invoice flow never
depends on it: an untracked line posts to accounting exactly like a tracked
one and simply creates no movement. Turning tracking on is a logged owner
action (`inventory.tracking_changed`, old → new, actor) and takes effect for
invoices **approved after** the change — earlier purchases are not
retroactively counted, because nobody knows what was on the shelf then. The
owner may enter an **opening count** at that moment; without one, the product's
stock is `NO OPENING COUNT`, shown as such, never as zero.

Example configuration, from the owner's instruction:

| Product | Tracked |
|---|---|
| Coca-Cola, Pepsi, water, other drinks | **TRACK** |
| meat, onion, tomato, sauces, bread | not tracked |

### 5.2 Movements

**`pl_inventory_movements`**

| Column | Meaning |
|---|---|
| `product_id` fk | |
| `quantity` integer thousandths of `stock_unit`, signed | +240 for ten cases of 24; the unit is the product's, never the invoice's |
| `movement_type` | `purchase` · `count_adjustment` · `manual_adjustment` · `reversal` |
| `source_type`, `source_id`, `source_line_no` | `purchase_invoice_line` → the line; `count` → the count row |
| `occurred_on` | invoice sale date for purchases, count date for counts |
| `recorded_by`, `recorded_at`, `reason` | |
| **unique (`source_type`, `source_id`, `source_line_no`)** | the idempotency wall: one line produces one movement, ever |

Conversion: line quantity 10, unit `op`, alias `pack_size` 24 `szt` → 240
`szt`. If the alias has no pack size and the invoice unit differs from the
stock unit, the line is REQUIRES REVIEW ("10 op. of Coca-Cola: how many szt in
an op.?") — asked once, remembered on the alias.

### 5.3 What the monthly model can and cannot know about stock

Sales are entered as **monthly totals**, so the system has no per-item sales
and **cannot decrement stock per sale**. The honest inventory picture for a
tracked product is therefore:

```
opening count (or NO OPENING COUNT)
  + purchases (automatic, from approved invoices)
  − implied consumption  =  physical count entered by the owner
```

Implied consumption is **derived from the count**, and shown as such. Without a
count, the page shows purchases-to-date and `NOT COUNTED`; it does not invent a
closing stock. Recipe-based theoretical consumption belongs to the separate
Shop Profit Intelligence application and is not pulled in here.

**`pl_inventory_counts`**: `product_id`, `counted_on`, `quantity`,
`counted_by`, `note`; a count creates a `count_adjustment` movement equal to
(count − book quantity) with the book quantity recorded on the count row, so
the arithmetic is replayable.

---

## 6. Monthly sales model

The existing model is already monthly and stays: one `pl_sales_reports` row
per (profile, period) in force, lines per VAT designation, gross entered, net
and VAT derived, unique index preventing a second August, corrections
superseding with a reason.

Changes:

- **Channel on each line.** `shop_register` lines come from the fiscal
  register's monthly report (letters A/B/… as today). `glovo` lines are written
  automatically when a platform settlement is recorded (section 7) so the owner
  types the Glovo month once, not twice. `other` covers a catering invoice the
  shop issued (an *outgoing* KSeF document, section 2.2).
- **Total = shop + Glovo**, and the report shows the split. The engine already
  sums lines; the split is presentation plus the JPK document type (section 10).
- **Nothing daily.** No table, column or job keyed by day. If a daily sales
  import is ever wanted, it is a separate optional importer that *produces* the
  monthly report, never a requirement of it.

The owner's example, as records:

| Period | Channel | Designation | Gross |
|---|---|---|---|
| 2026-08 | shop_register | per register letters | 48 000,00 zł |
| 2026-08 | glovo | per settlement | 12 000,00 zł |
| | | **total** | **60 000,00 zł** |

VAT by rate inside those figures comes from the register's letters for the
shop and from the platform statement's rate breakdown for Glovo. Where Glovo's
statement gives no rate breakdown, the line is REQUIRES REVIEW with the register
letter mapping offered as the default the owner must confirm — the system does
not assume a rate for 12 000 zł.

---

## 7. Glovo settlement model

Glovo is an **accounting and settlement source**, not a separate analytics
system. The shop-intelligence application keeps its own `PlatformSettlement`
for management analysis; nothing here shares code or tables with it.

### 7.1 The record

**`pl_platform_settlements`**

| Column | Meaning |
|---|---|
| `platform` | `glovo` (enum; `uber_eats`, `other` reserved) |
| `period` string(7) | the settlement month |
| `statement_from`, `statement_to` | the statement's own dates (a payout for August lands in September) |
| `gross_orders` | what customers paid, incl. VAT |
| `gross_orders_by_rate` json | rate breakdown when the statement gives it |
| `commission_net`, `commission_vat`, `commission_vat_rate` | Glovo's fee |
| `other_deductions` json + total | marketing, refunds, adjustments, each named |
| `payout_expected` | derived: gross − commission gross − deductions |
| `payout_received` decimal null | from the matched bank transaction; null = NO PAYOUT RECORDED |
| `bank_transaction_id` fk null | the match |
| `difference` | derived: expected − received; null when no receipt |
| `vat_treatment` | section 7.2 — **required before posting; UNKNOWN refuses** |
| `commission_invoice_document_id` fk null | Glovo's commission invoice if it arrived via KSeF |
| `source_type`, `source_checksum`, `source_filename` | `manual` or the imported statement; unique (profile, platform, period, source_checksum) |
| `status` | `recorded` · `reconciled` · `unreconciled` · `requires_review` · `no_payout_recorded` |
| `recorded_by`, `recorded_at`; `superseded_by_id` | corrections supersede |

### 7.2 Accounting and VAT treatment

Two facts the system must be **told**, because they change the VAT return and
cannot be inferred from the numbers:

1. **Whose sale is the order?** Under the common Glovo contract the restaurant
   sells the food to the customer and Glovo acts as intermediary; the gross
   order value is the shop's turnover (recorded on the shop's cash register or
   invoiced) and Glovo's commission is the shop's cost. The design follows
   this model: `gross_orders` → sales channel `glovo`; commission → purchase.
2. **Who issues the commission invoice, and from where?** If a Polish VAT
   taxpayer issues it, it is an ordinary domestic purchase: commission net is a
   cost, commission VAT is input VAT, and the invoice **arrives via KSeF** like
   any other. If a foreign entity issues it, it is an **import of services**:
   the shop self-assesses output VAT and, if entitled, deducts the same amount
   as input VAT, and the transaction is reported in the import-of-services
   fields of JPK_V7. The two cases produce different rows in the return.

`vat_treatment` therefore has three values: `domestic_invoice`,
`import_of_services`, `UNKNOWN`. It is set per settlement from the commission
invoice's issuer NIP/country when available, otherwise by the owner, **confirmed
with the accountant once** and then remembered as the default for the platform.
A settlement with `UNKNOWN` is recorded, reconciled against the bank, and
**refuses to post to VAT** with a caveat naming the decision. This is the same
refusal the engine makes for a missing rate.

Whether Glovo's deductions (marketing, promotions) carry VAT and which rate
applies to the orders themselves are also statement facts, not assumptions.
When the statement is silent, the field is `MISSING_FIELD` and the month is
REQUIRES REVIEW.

### 7.3 Reconciliation with the bank payout

The bank matcher gains a candidate type `platform_payout`: expected amount,
expected date window (statement_to + settlement delay), counterparty name
containing the platform. MATCHED only on amount to the grosz plus an
identifying reference; otherwise POSSIBLE → owner confirms. Difference handling
follows the existing posture: a gap is a **question with innocent explanations**
(refunds netted, an adjustment for another period, marketing fees outside the
commission line), never an accusation and never rounded to "fine".

---

## 8. VAT model

Assumes the profile says the shop is a **registered VAT payer**. If the profile
says exempt (art. 113), the engine already computes no VAT and only tracks the
exemption limit; sections 8 and 10 then produce nothing to file, and the report
says so.

### 8.1 Output VAT (VAT należny)

From the monthly sales report, per designation, gross → VAT = gross × r/(1+r),
exactly as today. Channels are summed. The RO document (monthly cash register
summary) and, where applicable, the Glovo channel are the JPK sales rows.
Typical rates for the shop, **as configured on the register letters, never
assumed here**: meals as a catering service, drinks, and packaged goods each
carry the rate the register was programmed with.

### 8.2 Input VAT (VAT naliczony)

From `pl_purchase_postings` in the `vat_period`, by rate, summed to
`deductibleInputVat`. Non-deductible parts are excluded and listed. A posting
whose document is `awaiting_review` or `rejected` contributes nothing — the
register contains **approved** documents only.

### 8.3 Deduction period

The buyer may deduct input VAT no earlier than the period in which the invoice
was received; for a KSeF invoice the receipt date is the date the KSeF number
was assigned (`permanent_storage_date`). The right may be exercised in that
period or one of the following periods the law allows for monthly filers. The
design therefore sets `vat_period` = the later of (invoice date month, receipt
month) by default and lets the owner defer within the allowed window at review,
recording the choice. It never deducts earlier than receipt, and it flags an
invoice whose allowed window has passed.

### 8.4 Result

`VatCalculator` is unchanged: output − input − carry-forward, rounded to whole
złoty, surplus carried or refundable and stored in its own column so it can
never render as an amount due. The only change is where the `PurchaseRegister`
comes from (section 4.3).

---

## 9. PIT model

The engine already handles all three regimes and replays the year. What the new
inputs change:

- **Ryczałt.** Revenue (net for a VAT payer) from the monthly sales report,
  both channels. Costs do not reduce the base; approved invoices matter only
  for VAT. The ryczałt rate remains an **owner/accountant decision recorded in
  the profile** (gastronomy commonly carries a different rate from beverages
  above a given alcohol content, and a mixed activity may carry two rates —
  `docs/OPEN_ITEMS.md` P0 still says the rate has not been determined). Per-line
  `lump_sum_rate` on sales lines already exists for a two-rate business.
- **Scale / flat tax.** Costs now come from postings, so PIT stops being an
  upper bound for months with approved invoices. Each posting carries
  `cost_category` mapped to the KPiR column it belongs in:

  | `cost_category` | KPiR column |
  |---|---|
  | `goods_for_resale_and_materials` (drinks, meat, bread, vegetables, packaging) | 10 — zakup towarów handlowych i materiałów |
  | `purchase_side_costs` (transport, insurance of purchases) | 11 — koszty uboczne zakupu |
  | `other_expenses` (rent, utilities, Glovo commission, repairs, services) | 13 — pozostałe wydatki |
  | `wages` | 12 — wynagrodzenia (not expected; present for completeness) |

  The category comes from the product's `default_cost_category` when a line is
  mapped, else from the supplier's last used category, else it is asked once at
  review. A month whose regime deducts costs but has documents outside KSeF is
  still reported with the caveat "costs may be incomplete", because absence is
  not zero.
- **ZUS** is unchanged and still rate-table-gated (`POLAND_REQUIRE_OFFICIAL_RATES`).
  The ryczałt health band reads year-to-date revenue, which now includes the
  Glovo channel.

---

## 10. JPK_V7 future integration

Not built; the ports exist (`DocumentPreparer`, `SchemaRegistry`,
`PreparedDocument`, `DocumentSubmitter` bound to refuse). The new data makes
preparation possible for the first time, because JPK_V7 needs **per-invoice
purchase rows**, which monthly totals could not supply.

Mapping, structure JPK_V7M (monthly) or V7K per the profile:

| JPK part | Source |
|---|---|
| Sales rows | one row per month from the cash register report, document type **RO**, amounts by rate from `pl_sales_report_lines` (channel `shop_register`, plus `glovo` when Glovo orders are recorded through the register or as a separate summary — accountant confirms the document type) |
| Sales rows — import of services | from `pl_platform_settlements` with `vat_treatment = import_of_services`: the self-assessed output VAT |
| Purchase rows | one row per posting in `vat_period`: seller NIP, name, invoice number, invoice date, receipt date, net and VAT by rate; `gtu`/`procedure` markers from lines when present; corrections as negative rows referencing the KOR |
| Declaration part | from `VatSettlement`: output, input, carry-forward in, amount to pay or surplus |

Rules carried over from `docs/ARCHITECTURE.md`: schema version selected by
period, XSD validated at preparation, preparation refused while rates are
unverified or the month is an estimate, every prepared document stored with
checksum and idempotency key, **filing stays disabled**. The taxpayer files
through the Ministry's tools and records the reference; `FilingChannel::JpkV7`
becomes automated only in a separately approved stage with its own
authorisation.

---

## 11. Bank reconciliation

Exists (CSV, MT940, camt.053; balance-checked; completeness recorded; cross-format
duplicates flagged; deterministic matcher). Additions:

| Candidate type (new) | Built from | Effect of MATCHED / accepted POSSIBLE |
|---|---|---|
| `purchase_invoice` | approved postings: gross, due date, supplier account (`NrRB`), invoice number | the document's payment status becomes PAID with date and bank line; **no effect on VAT or cost** — payment is a fact about money, not about deduction |
| `platform_payout` | `pl_platform_settlements`: expected payout, window | settlement `payout_received`, `difference`, status |
| `card_terminal_settlement` | none yet — recognised by counterparty keywords as a **category** (revenue), never matched to a document | informs the cash/card split of the month's takings; does not change the sales figure, which comes from the register |
| `zus_payment`, `tax_payment` | exist | payment checklist |

Cash sales never reach the bank except as deposits; the report says which part
of the month's takings was seen in the bank and which was not, without treating
the unseen part as missing revenue.

Invoices paid in cash or by card at the counter (`FormaPlatnosci` 1 or 2, or
`Zaplacono = true`) are marked `paid_on_invoice` and are not expected in the
bank; the matcher does not chase them.

---

## 12. Duplicate invoice protection

Four walls, each in the database, in the order they catch things:

1. **Same KSeF number twice** — unique (`tax_profile_id`, `ksef_number`) on
   `pl_ksef_documents`. Exists. A retried sync, a re-requested window, a
   timeout after the server accepted: all land here and are counted as
   `duplicates`, never imported.
2. **Same invoice under two KSeF numbers** — a supplier re-issuing, or a
   manual entry later duplicated by the KSeF copy. Detection on (seller NIP,
   normalised invoice number, invoice date, gross): the second document is
   imported, set `possible_duplicate_of` the first, `awaiting_review` with the
   reason, and **excluded from every register until the owner decides**. Same
   posture as the bank cross-format duplicate: neither silently doubled nor
   silently dropped.
3. **Same document posted twice** — unique `ksef_document_id` on
   `pl_purchase_postings`. Approving twice (double tap, two browser tabs, a
   retried job) cannot create a second cost.
4. **Same line moving stock twice** — unique (`source_type`, `source_id`,
   `source_line_no`) on `pl_inventory_movements`.

And one rule outside the database: **corrections are reversals, not duplicates.**
A KOR reverses its original through `reverses_posting_id`; the original's
movement is reversed by a `reversal` movement pointing at it. No row is deleted
in any of these paths, so a retry after a partial failure is always safe to
run again.

---

## 13. Audit trail

`pl_audit_events` exists, append-only, refusing updates and deletes at the
model. Every new action appends with actor, subject, period, old/new values,
result and source. New action constants:

| Action | When | old → new |
|---|---|---|
| `ksef.invoice_awaiting_review` | after import | — → status, reasons |
| `ksef.invoice_approved` / `ksef.invoice_rejected` | owner decision | status; note; what will post |
| `ksef.invoice_auto_approved` | rule fired | rule id, conditions checked |
| `purchase.posted` / `purchase.reversed` | posting | amounts, periods, category, reason |
| `product.created` / `product.mapped` | master data | alias, pack size |
| `inventory.tracking_changed` | toggle | false → true (or back), opening count if given |
| `inventory.movement_recorded` / `inventory.count_recorded` | | quantities, source |
| `sales.recorded` / `sales.corrected` | exist | now with channel split |
| `platform.settlement_recorded` / `platform.settlement_reconciled` | | figures, vat_treatment, difference |
| `supplier.auto_approve_rule_changed` | | conditions, enabled |

Actor is always named; a scheduler run is `system:ksef-sync`, auto-approval is
`system:auto-approve#id`. The audit trail plus the immutable XML plus the
append-only postings are what let an accountant or an auditor replay any month
exactly as it stood on any date.

---

## 14. Privacy and external connections

### 14.1 The rule

Accounting data stays on the owner's server. **Default deny.** Every outbound
connection the application makes must be (a) listed in one place, (b) off by
default, (c) enabled by an explicit configuration change the owner made, and
(d) logged when used. The only planned government connection is KSeF, and it
is enabled by `KSEF_TRANSPORT_ENABLED=true` with a real transport and an
InvoiceRead token — nothing else.

### 14.2 Audit of this repository (`modules/poland`, `shop-intelligence`, `bin/`, `deploy/`)

Read on 2026-09-10 against branch `claude/determined-newton-e4kklt`.

| Finding | Verdict |
|---|---|
| No `Http::`, Guzzle, `curl_*`, `fsockopen` or `file_get_contents(http…)` anywhere in module source | **No outbound call exists.** `FaInvoiceParser` loads XML with `LIBXML_NONET` so an external entity in an invoice cannot trigger a fetch |
| `KsefClient` bound to `UnconfiguredKsefClient`; `TransportGate` throws if production selects a fake | refuses; **not connected** |
| `ExchangeRateProvider` bound to `UnavailableExchangeRateProvider`; `NBP_BASE_URL` present in `.env.example` | refuses; **not connected**; Phase 2 when built must go through the allowlist |
| `DocumentSubmitter` = `UnconfiguredSubmitter`; `FilingChannel::automated()` false for all | **nothing is filed** |
| URLs in `config/rates/*.php` | documentation of sources; never fetched |
| `MAIL_MAILER=log` in `.env.example` | no e-mail leaves the server. `bin/enable-email-verification.sh` would need a mailer; keep it off unless the owner chooses one |
| Blade views in `modules/poland/resources/views` | no CDN, font or script from another host |
| shop-intelligence core | no HTTP client at all; isolation script check 8 asserts it |

### 14.3 Audit of the Liberu ERP foundation (pinned `3a23437a…`, read from a clone of that commit)

The foundation is a general-purpose SaaS ERP. It **contains many outbound
integrations**. None is required by this design, and every one must be
disabled, unconfigured or removed on the box. The audit could only read the
code; verifying what the *installed* instance actually does needs the checks in
14.5, on the server.

| Component in the foundation | What it can send, where | Default | Required action |
|---|---|---|---|
| `laravel/socialite` + `bursteri/socialstream`; `config/services.php` GitHub/Google/Facebook/Twitter/GitLab/Bitbucket/LinkedIn/Slack | OAuth login redirects and token exchange with those providers | keys empty | keep every `*_CLIENT_ID` empty; disable social login in Socialstream config |
| `laravel/vonage-notification-channel`, `app/Notifications/Channels/SmsChannel.php`, per-team Vonage keys in the setup wizard | SMS via Vonage API | keys empty | never enter keys; disable SMS channel |
| `laravel/telescope` (`TELESCOPE_ENABLED` **defaults to true**), `laravel/pulse`, `laravel/horizon` | local dashboards; **Telescope records request bodies and queries into the database** | Telescope on | set `TELESCOPE_ENABLED=false`; Pulse off; Horizon dashboard super-admin only (it is) |
| `laravel/reverb`, `pusher/pusher-php-server`, `js.pusher.com` reference | websockets / Pusher cloud | `BROADCAST_CONNECTION=log` | keep `log`; never set Pusher keys |
| `spatie/laravel-backup` (`config/backup.php`) | backup destination disks; notifications by mail/Slack/Discord/webhook | disk `local`, notifications `mail` | keep destination local; with `MAIL_MAILER=log` nothing leaves; the project's own `deploy/backup.sh` is the backup that is verified |
| `app/Services/SaasPremiumBillingService.php` → `api.stripe.com` | billing | `PREMIUM_ENABLED=false` | keep false; no `STRIPE_*` |
| `app/Services/Hmrc*` (UK tax API), `Sage`, `Xero`, `QuickBooks`, `Plaid`, `Revolut`, `Wise`, `BankFeedService`, `ExchangeRateService`; modules `accounting-plaid/-wise/-revolut`, `*-migration` | third-party finance APIs and **bank feeds** | keys empty | never configure; disable the modules in the module manager so their screens do not exist |
| AWS variables, `sqs.us-east-1.amazonaws.com` in queue config | S3 storage / SQS queue | empty; queue is Redis | keep `FILESYSTEM_DISK=local`, `QUEUE_CONNECTION=redis` |
| `ui-avatars.com` default avatar URL in `app/Filament/Admin/Resources/Users/…` | **the browser fetches an image from a third party with the user's name in the URL** | active for users without an avatar | override the default image or block with a Content-Security-Policy `img-src 'self' data:` in nginx (14.4) |
| vcs modules `module-analytics-google`, `module-analytics-meta`, `module-localization-mymemory` (calls the MyMemory translation API), `module-webhooks`, `module-observability`, `module-notifications` | analytics tags, translation API, outbound webhooks | not configured | disable in the module manager; no analytics ID; no webhook endpoints |
| `thiagoalessio/tesseract_ocr` | local binary only | not installed | no network; acceptable if OCR is ever installed |
| `.mcp.json`, `boost.json`, `.claude/`, `.cursor/` | developer tooling in the upstream repo | not runtime | not deployed by the installer; irrelevant on the server |
| Composer/Packagist/GitHub, `ondrej`/`sury` apt repositories, `getcomposer.org`, Let's Encrypt | **deploy-time** only | used by `bin/deploy-contabo.sh` | acceptable; happens during deploy, not while accounting runs. Note that issuing a TLS certificate publishes the hostname in certificate-transparency logs, which is unavoidable for HTTPS |

The foundation's `config/mail.php` defaults to `smtp`, but the project's own
`.env.example` sets `MAIL_MAILER=log`, and the installer copies that file. Keep
it that way.

### 14.4 Enforcement, so the rule does not depend on remembering

1. **Egress allowlist at the OS.** On the accounting systemd units
   (`accounting-queue.service`, `accounting-scheduler.service`) and the PHP-FPM
   pool: `IPAddressDeny=any` plus `IPAddressAllow=` for localhost, the database
   and Redis, and — only when KSeF is enabled — the resolved KSeF hosts. A
   library that tries to phone home then fails loudly instead of quietly
   succeeding. Deploy-time package fetching runs outside these units.
2. **Content-Security-Policy in nginx**: `default-src 'self'; img-src 'self'
   data:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src
   'self'`. This blocks the browser-side leaks (avatars, CDN scripts) regardless
   of what the foundation's views contain.
3. **One registry in code.** `config/poland.php` gains an `egress` section
   listing every permitted destination with `enabled`, `purpose`, `data_sent`,
   `authorised_by`, `authorised_on`. The real `KsefClient` reads its base URL
   from there and refuses if the entry is disabled. A test asserts the registry
   is the only place a hostname appears in module code.
4. **`bin/check-isolation.sh` gains a privacy check** that fails if any
   third-party key from 14.3 is set to a non-empty value in the deployed `.env`,
   if `TELESCOPE_ENABLED` is not `false`, or if `MAIL_MAILER` is not `log`
   without an explicit `POLAND_MAIL_AUTHORISED=true`.
5. **No AI provider.** `InterpretationBoundary` already forbids an
   interpretation from writing engine fields. This design adds nothing that
   calls a model. If one is ever wanted (for example to suggest product
   mappings), it runs **locally or not at all**, is registered in `egress`, and
   remains subject to the boundary.

### 14.5 What must be verified on the server (cannot be done from here)

- `ss -tnp` and nginx/queue logs over a normal week show no destination other
  than localhost, the database, Redis and (when enabled) KSeF.
- `php artisan route:list` on the installed instance shows no Telescope, Pulse,
  Socialstream or third-party integration routes exposed.
- The module manager lists Plaid, Wise, Revolut, migrations, analytics,
  localisation, copilot and webhooks as disabled.
- `curl -I https://account.signalmesh.dev` shows the CSP header.

---

## 15. Migration strategy from the current monthly model

Nothing existing is removed. Each stage is additive and independently
reversible; each ships with tests (section 16) and a `CHANGELOG.md` note.
**None of these stages is authorised by this document.** Each is approved
separately.

| Stage | Scope | Depends on | Owner-visible change |
|---|---|---|---|
| **A** (this document) | design | — | none |
| **B — schema and review inbox** | additive columns on `pl_ksef_documents`; `pl_purchase_invoice_lines`, `pl_suppliers`, `pl_products`, `pl_product_aliases`, `pl_purchase_postings`, `pl_inventory_movements`, `pl_inventory_counts`, `pl_platform_settlements`; line parsing in `FaInvoiceParser`; approval service; posting service; `PurchaseRegister` read model from postings; `channel` on sales lines; inbox, product and settlement screens | nothing external — exercised with the existing FA fixtures and the fake transport in the **test** configuration only | the inbox exists but says NOT CONNECTED; products can be created and tracking toggled; Glovo month can be entered; monthly report starts using postings when there are any |
| **C — real KSeF transport** | `KsefClient` implementation against the current official API; egress registry; systemd egress allowlist; 12-step go-live checklist against the KSeF test environment; then production token with InvoiceRead | official API access from the server; a human with network access | invoices start arriving by themselves |
| **D — Glovo statement importer** | a fail-closed parser for Glovo's export format (format to be captured from a real statement first; a parser written from memory of a format is worse than none), producing `pl_platform_settlements` rows | a real statement file to build from | Glovo month is an import instead of five typed figures |
| **E — JPK_V7 preparation** | `DocumentPreparer` for JPK_V7M/K, XSD registered in `SchemaRegistry` by period, prepared documents stored; filing still disabled | official XSD, verified rates (`OFFICIAL_RATES_NOT_VERIFIED` blocks preparation by design) | a validated JPK file to download and file through the Ministry's tools |
| **F — trusted-supplier auto-approval** | the rule of 3.4 | B in use for several months, so N manual approvals exist | most weeks require zero taps |

**Coexistence rules during B onward:**

- `pl_purchase_summaries` keeps working for months before postings exist. A
  month with both is REQUIRES REVIEW until the manual summary is superseded;
  the two are never summed.
- `SettlementEngine`, the calculators and `Domain` do not change. The
  framework-free engine still runs from `bin/pl-tax` with a monthly sales figure
  and an optional purchase register, exactly as today.
- Reports already generated are never rewritten; a new posting for a settled
  month raises `NEW_DOCUMENTS_AFTER_REPORT` and the owner regenerates version
  n+1 (exists).

---

## 16. Required tests

Named in the repository's style; each pins a rule stated above. Suites to add
under `modules/poland/tests/` in stage B unless marked otherwise.

**FaInvoiceParser — lines and payment (`FaInvoiceLinesTest`)**
- `test_every_fa_wiersz_becomes_one_line_in_document_order`
- `test_line_totals_are_checked_against_header_totals_per_rate`
- `test_a_line_without_vat_amount_derives_it_and_says_it_is_derived`
- `test_missing_quantity_or_unit_stays_missing_never_one_or_blank`
- `test_payment_terms_due_date_form_and_account_are_read`
- `test_absent_zaplacono_is_null_not_false`
- `test_a_correction_links_to_its_original_by_ksef_number_then_by_number_and_nip`
- `test_a_schema_version_with_unknown_line_elements_reports_them`

**Approval (`InvoiceApprovalTest`)**
- `test_every_imported_invoice_enters_review_before_anything_posts`
- `test_approving_posts_accounting_and_stock_in_one_transaction`
- `test_a_failure_during_posting_rolls_back_stock_and_leaves_the_invoice_approved_not_posted`
- `test_rejecting_requires_a_reason_and_deletes_nothing`
- `test_an_incomplete_parse_cannot_be_approved_only_reviewed`
- `test_an_interpretation_can_suggest_a_mapping_but_cannot_approve`
- `test_auto_approval_fires_only_when_every_condition_holds` (stage F)
- `test_auto_approval_is_audited_with_the_rule_that_fired` (stage F)

**Posting and duplicates (`PurchasePostingTest`)**
- `test_the_same_document_cannot_be_posted_twice_even_from_two_processes`
- `test_a_correction_reverses_the_original_and_posts_the_new_amounts`
- `test_a_difference_only_correction_asks_rather_than_guesses`
- `test_a_posting_is_never_updated_only_reversed`
- `test_vat_period_is_never_earlier_than_receipt`
- `test_an_invoice_past_its_deduction_window_is_flagged`
- `test_the_same_invoice_under_two_ksef_numbers_is_flagged_and_excluded`
- `test_the_purchase_register_is_the_sum_of_posted_documents_only`
- `test_a_month_with_manual_summary_and_postings_is_review_not_a_sum`

**Inventory (`OptionalInventoryTest`)**
- `test_products_are_untracked_by_default`
- `test_an_untracked_line_posts_accounting_and_moves_no_stock`
- `test_a_tracked_line_moves_stock_in_the_product_unit_via_pack_size`
- `test_a_line_with_unknown_pack_size_asks_once_and_remembers`
- `test_one_line_can_never_produce_two_movements`
- `test_enabling_tracking_does_not_backfill_earlier_purchases`
- `test_no_opening_count_renders_as_no_opening_count_not_zero`
- `test_a_count_records_the_book_quantity_it_was_compared_to`
- `test_implied_consumption_exists_only_when_a_count_exists`

**Monthly sales (`MonthlySalesChannelsTest`)**
- `test_shop_and_glovo_lines_sum_to_the_month_and_keep_their_split`
- `test_recording_a_platform_settlement_writes_the_glovo_sales_line_once`
- `test_no_table_or_query_is_keyed_by_day` (a structural test over the migrations directory)
- `test_a_second_report_for_the_same_month_is_refused_correction_supersedes` (exists)

**Glovo (`PlatformSettlementTest`)**
- `test_expected_payout_is_gross_minus_commission_gross_minus_named_deductions`
- `test_no_payout_recorded_is_never_reconciled`
- `test_unknown_vat_treatment_records_but_refuses_to_post_vat`
- `test_import_of_services_produces_output_and_input_rows`
- `test_domestic_commission_invoice_is_linked_to_its_ksef_document`
- `test_a_payout_difference_is_a_review_flag_with_innocent_explanations`
- `test_a_missing_rate_breakdown_is_missing_field_not_a_default_rate`

**Bank (`ReconciliationTest`, extended)**
- `test_a_supplier_transfer_matches_the_approved_invoice_by_amount_and_reference`
- `test_marking_an_invoice_paid_changes_no_vat_and_no_cost`
- `test_a_platform_payout_matches_within_the_settlement_window`
- `test_cash_paid_invoices_are_not_expected_in_the_bank`

**Privacy (`EgressPolicyTest`, `bin/check-isolation.sh`)**
- `test_no_hostname_appears_in_module_code_outside_the_egress_registry`
- `test_the_ksef_client_refuses_when_its_egress_entry_is_disabled`
- `test_no_interpretation_path_reaches_a_network_client`
- shell: fail on any third-party key set, Telescope enabled, or a mailer other than `log` without explicit authorisation

**KSeF transport (stage C, against the test environment; recorded in the release record, not unit tests)**
- the 12 steps of the production audit §1, each with its observed result

**JPK (stage E, `JpkV7PreparationTest`)**
- `test_one_ro_row_per_month_from_the_register`
- `test_one_purchase_row_per_posting_in_its_vat_period`
- `test_corrections_appear_as_negative_rows_referencing_the_kor`
- `test_preparation_is_refused_on_unverified_rates_or_an_estimate` (exists as a rule)
- `test_the_document_validates_against_the_registered_xsd_for_its_period`

**Regression, must keep passing**: all 309 existing tests, `ReleaseGateTest`,
`EngineBoundaryTest` (46), `CredentialSecurityTest` (11), `SyncSafetyTest` (13),
`IsolationTest` in shop-intelligence, both isolation scripts.

---

## 17. Which parts already exist

Built, executed and tested on branch `claude/determined-newton-e4kklt` (see
`docs/PRODUCTION_AUDIT_2026-09-07.md`, `docs/RELEASE_RECORD_2026-09-07.md`):

- Tax engine: `Money`, `Period`, `TaxProfile`, `FiscalSalesReport`,
  `PurchaseRegister`, `Ledger`; `VatCalculator`, `ZusCalculator`,
  `PitCalculator`; `SettlementEngine::replayYear`; rate tables with provenance
  and refusal; deadlines calendar.
- Monthly sales: `pl_sales_reports` + lines, unique per month, corrections
  supersede; dashboard entry; manual monthly purchase summary.
- Reports: immutable `pl_report_versions`, payment checklist
  `pl_payment_obligations`, close/reopen with reason, certainty states and
  caveats, integration status panel.
- KSeF pipeline minus transport: credentials (encrypted, InvoiceRead-only),
  sync state and cursor rule, `pl_ksef_documents` with immutable XML and
  header fields, FA parser (header and per-rate totals, namespace-agnostic,
  unknown elements recorded), duplicate wall by KSeF number, REQUIRES REVIEW
  propagation, `TransportGate`, `UnconfiguredKsefClient`.
- Bank: three parsers, balance and completeness checks, fingerprints,
  cross-format duplicate flagging, deterministic matcher, decisions never
  overwritten.
- Government inbox, AI boundary (`InterpretationBoundary`), refusing ports
  for OCR, exchange rates, schema, filing.
- Append-only audit trail with actor.
- Isolation from trading, verified both directions; deploy scripts; verified
  backup.

---

## 18. Which parts are missing

Not built, in the order the stages of section 15 would build them:

1. Line-item and payment-terms parsing (`FaWiersz`, `Platnosc`, `DaneFaKorygowanej`).
2. The review inbox and approval states; approval and posting services.
3. `pl_purchase_invoice_lines`, `pl_suppliers`, `pl_products`,
   `pl_product_aliases`, `pl_purchase_postings`.
4. Purchase register read model from postings; coexistence rule with the
   manual summary.
5. Optional inventory: `pl_inventory_movements`, `pl_inventory_counts`,
   tracking toggle, pack-size conversion, count-based implied consumption.
6. Sales channel on lines; Glovo line written from the settlement.
7. `pl_platform_settlements`, VAT treatment enum with refusal on UNKNOWN,
   payout candidate in the matcher.
8. Purchase-invoice payment candidate in the matcher; paid-on-invoice handling.
9. New audit action constants and their tests.
10. Egress registry in config, systemd egress allowlist, nginx CSP, privacy
    check in `bin/check-isolation.sh`.
11. Foundation hardening on the box: Telescope off, unused modules disabled,
    third-party keys absent, avatar URL overridden (verified per 14.5).
12. KSeF HTTP transport (stage C).
13. Glovo statement importer (stage D).
14. JPK_V7 preparer and XSD registration (stage E).
15. Trusted-supplier auto-approval (stage F).
16. Still open and unchanged from `docs/OPEN_ITEMS.md`: official rate
    verification (P0, blocks every real figure), the ryczałt rate decision,
    the VAT status confirmation, Polish chart of accounts (only if full books
    are ever needed), OCR toolchain, NBP adapter, backup drill on the real
    database.

Two accountant questions this design cannot answer and must not guess:
**Glovo's VAT treatment for this shop's contract** (domestic invoice or import
of services) and **which document type the Glovo orders take in JPK** (through
the register as RO, or a separate summary). Both have a place in the model
(`vat_treatment`, the sales channel) and both refuse to post until answered.

---

## 19. Which parts depend on real KSeF transport

Everything in this design can be built and tested **without** the transport,
using the FA fixtures already in `tests/Fixtures/` and the fake transport in
the test configuration. The parts that cannot deliver value to the owner until
the transport exists:

| Part | Without transport | With transport |
|---|---|---|
| Automatic invoice arrival (section 2) | inbox shows NOT CONNECTED; nothing arrives; the report says documents are not seen | invoices arrive on the scheduler's cadence |
| Review inbox, approval, posting, stock (sections 3–5) | fully testable with fixtures; empty in production | live |
| Purchase register from postings, input VAT (sections 4.3, 8.2) | empty → VAT stays an upper bound, as today | exact for KSeF-covered purchases |
| PIT costs for scale/flat (section 9) | upper bound, as today | exact for KSeF-covered costs |
| JPK_V7 purchase rows (section 10) | none | one per posting |
| Bank matching to invoices (section 11) | only ZUS/tax/payout candidates | supplier payments matched |
| Duplicate walls (section 12) | in place, exercised by tests | exercised daily |
| Glovo settlement, monthly sales, ZUS, output VAT, payment checklist, audit trail, privacy enforcement | **independent of KSeF**; work now | unchanged |

The transport itself is gated by the production audit's checklist and by
`TransportGate`; until it passes, `KSEF_TRANSPORT=disabled` and every screen
says so in words that cannot be read as "no invoices".

---

## Appendix — the workflow the owner asked for, as one worked example

*Figures are illustrative. The VAT status, the ryczałt rate and every ZUS
amount come from the profile and the rate tables; the rate tables are still
NOT VERIFIED, so a real report today would carry that banner and, with
`POLAND_REQUIRE_OFFICIAL_RATES=true`, would refuse to settle.*

**Tuesday 11 August 2026, 09:40.** Hurtownia Napojów Sp. z o.o. delivers and
issues invoice FV/2026/08/417 to the shop's NIP. KSeF assigns number
`5261040828-20260811-A1B2C3D4E5F6-01` at 09:52. *(Owner does nothing.)*

**11:00, scheduler.** `KsefIngestService::sync` asks KSeF for invoices
received since the last confirmed mark (yesterday 21:00). One page, one new
invoice; the XML is fetched and stored verbatim with its SHA-256; the unique
index accepts the KSeF number. Parsing reads:

| Line | Description | Qty | Unit | Net | VAT | Gross |
|---|---|---|---|---|---|---|
| 1 | Coca-Cola 0,5 l karton 24 szt | 10 | op | 1 200,00 | 23% 276,00 | 1 476,00 |
| 2 | Woda Żywiec 0,5 l karton 12 szt | 5 | op | 200,00 | 23% 46,00 | 246,00 |
| 3 | Cebula żółta | 20 | kg | 64,00 | 5% 3,20 | 67,20 |
| | **Totals agree with header** | | | **1 464,00** | **325,20** | **1 789,20** |

Payment: przelew, due 25.08.2026, account PL61 1090 …. Lines 1 and 2 match
aliases the owner mapped in July (Coca-Cola → tracked, 1 op = 24 szt; Woda →
tracked, 1 op = 12 szt). Line 3 matches the alias "cebula" → product Cebula,
**not tracked**. Status: `awaiting_review`. Audit: `ksef.invoice_awaiting_review`,
actor `system:ksef-sync`. *(Owner does nothing.)*

**13:15, owner's phone, one tap.** The inbox shows the invoice and the
sentence: *"Posts 1 400,00 zł net + 322,00 zł VAT (23%) and 64,00 zł net +
3,20 zł VAT (5%) to August 2026 (VAT period 2026-08). Increases stock:
Coca-Cola 0,5 l +240 szt; Woda 0,5 l +60 szt. No stock change: Cebula 20 kg
(not tracked)."* Approve.

In one transaction: `pl_purchase_postings` gets one row (document #, period
2026-08, vat_period 2026-08, by_rate {23: 1 400/322, 5: 64/3,20}, category
goods_for_resale_and_materials, posted_by owner); `pl_inventory_movements`
gets two rows (+240 000 thousandths szt Coca-Cola, +60 000 thousandths szt
Woda, source line 1 and 2); the document becomes `posted`; audit records
`ksef.invoice_approved`, `purchase.posted`, `inventory.movement_recorded` ×2.
Tapping Approve again does nothing: the unique posting key refuses.

**Later in August.** The same happens for meat, bread and the second drinks
delivery. Glovo's commission invoice arrives via KSeF from a Polish issuer,
maps to supplier Glovo, category other_expenses, `vat_treatment` remembered as
`domestic_invoice`. The bank import on 26 August matches the 1 789,20 zł
transfer to FV/2026/08/417 by amount and reference; the invoice is PAID. VAT
and cost do not move.

**1 September, month end, ten minutes.** The owner enters the register's
August report: letter A (23%) 6 000,00 zł, letter B (8%) 42 000,00 zł — total
48 000,00 zł. Then the Glovo month: gross orders 12 000,00 zł (statement rate
breakdown 8%), commission 3 600,00 net + 828,00 VAT (linked to the KSeF
invoice already posted), other deductions 0, payout received 7 572,00 zł
matched on the 4 September bank line. The Glovo sales line is written once.
Total sales August: **60 000,00 zł**, split shown.

**The monthly picture the engine produces** (VAT-registered profile assumed):

```
VAT należny     A 23%   6 000,00 × 23/123 =   1 121,95
                B  8%  42 000,00 ×  8/108 =   3 111,11
                Glovo  12 000,00 ×  8/108 =     888,89
                                              -----------
                                                5 121,95
VAT naliczony   from 9 approved invoices        (1 731,20)   ← sum of postings, vat_period 2026-08
                                              -----------
VAT do zapłaty  (rounded to złoty)              3 391,00     termin 25.09.2026
Nadwyżka        0,00

Przychód (net, both channels)                  54 878,05    → ryczałt at the profile rate, or
                                                              income = przychód − koszty from postings
                                                              for skala/liniowy
ZUS             from rate tables — NOT VERIFIED → BLOCKED until confirmed
Certainty       CALCULATED (bank COMPLETE, 0 possible duplicates, 0 unmatched)
Caveats         OFFICIAL_RATES_NOT_VERIFIED · documents outside KSeF are not seen
Stock           Coca-Cola 0,5 l: opening 96 + purchases 480 = 576 szt, NOT COUNTED
                Woda 0,5 l:      opening 24 + purchases 120 = 144 szt, NOT COUNTED
                Cebula:          not tracked
Inbox           0 awaiting review · 0 rejected · 9 posted
```

The owner reads it, pays VAT and ZUS from the bank, the next statement import
turns both PAID, and the month is closed. Nothing was typed except two sales
figures and the Glovo statement; nothing left the server except the KSeF
InvoiceRead session; and every number on the page can be traced to a KSeF
document, a register report, a statement line, a rate-table version and an
approval with a name and a time.
