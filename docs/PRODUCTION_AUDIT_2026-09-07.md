# Production audit — 2026-09-07

A full production audit of the accounting application, recorded here permanently
as the standing statement of what is safe, what is not, and why.

**Auditor's verdict:** the design is behaving like a serious accounting system.
The strongest decisions are fail-closed behaviour, no fake KSeF, no fake OCR, no
empty-result interpretation, database-level deduplication, cursor advancing only
after complete synchronisation, immutable source documents, versioned reports,
an AI that cannot write engine-owned figures, disagreement becoming MANUAL
REVIEW, worst-case certainty, and automated filing disabled.

**Standing instruction: do not weaken these to make the feature list look
bigger.** Every one of them is now covered by a test that fails if it is.

---

## The governing principle

> **Missing data is not zero data.**

The system must never read any of these as evidence that nothing exists:

- an empty KSeF result
- an empty OCR result
- a missing bank statement
- unavailable government document text

Every one of these paths throws rather than returning empty. Tested.

---

## Findings and disposition

Each audit section, what it required, and what was done.

| § | Requirement | Disposition |
|---|---|---|
| 1 | KSeF architecture acceptable; keep 13 named properties | **Confirmed kept.** Each is now pinned by a test |
| 1 | Do not implement a client from remembered documentation | **Honoured.** Transport unimplemented; 12-step checklist recorded below |
| 2 | Cursor: validate → persist → commit → advance; never advance on failure | **Already correct.** Now covered by an explicit test |
| 3 | Missing net must read `MISSING_FIELD`, not `0.00` | **Added.** `ParsedInvoice::MISSING` and `display()`; nothing renders a fabricated zero |
| 3 | Preserve unknown XML fields | **Added.** `unknownElements` records what the mapping does not cover |
| 4 | A regression test for each of ten token-security properties | **Added.** `CredentialSecurityTest`, one test per property |
| 5 | Keep OCR failing closed | **Confirmed kept**, tested |
| 6 | Do not loosen the transaction fingerprint | **Confirmed kept.** Loosening is now tested against |
| 7 | Explicit transaction lifecycle; never delete a duplicate | **Confirmed.** Rows are flagged and excluded, never deleted |
| 8 | Permanent regression tests for data-type comparison | **Added.** `ValueComparisonTest`, 19 tests |
| 9 | Keep the AI boundary | **Confirmed kept** |
| 10 | Tests proving AI cannot write each named figure | **Added, and a real gap closed** — see below |
| 11 | Disagreement shows both facts, favours neither | **Confirmed**, tested including direction symmetry |
| 12 | Worst-case certainty, never averaged | **Confirmed**, tested |
| 13 | Six distinct states including BLOCKED and FAILED | **Gap closed** — see below |
| 14 | Monthly close must show why certainty is not higher | **Confirmed.** Every step reports its outcome and every caveat its reason |
| 15 | Settled report immutability with full version metadata | **Confirmed kept**, tested |
| 16 | Statement completeness must be known | **Gap closed** — see below |
| 17 | Original PDF always retained | **Confirmed.** Never store only a summary |
| 18 | Interpretation provenance | **Partly present**; page number and model version noted as open |
| 19 | Legally significant actions behind approval | **Confirmed.** `automated() === false` test retained |
| 20 | Explicit transport gate, never a silent fallback | **Gap closed** — see below |
| 21 | Rate verification remains the production gate | **Confirmed kept** |
| 22 | UI must label unavailable integrations | **Gap closed** — see below |
| 23 | Do not claim capabilities that are not live | **Confirmed.** See "What is not claimed" |

---

## Four real gaps the audit found, and how they were closed

### §13 — BLOCKED and FAILED did not exist

The system had four certainty states. The audit named six. The two missing ones
are not decoration: they answer *who can fix this*.

- **NOT ENOUGH DATA** — the taxpayer uploads a statement and it clears.
- **BLOCKED** — a required dependency is unavailable or unverified. Nothing the
  taxpayer uploads changes an unverified rate table.
- **FAILED** — something ran and broke. There is an error to diagnose and a
  retry that might work.

Collapsing these sends somebody hunting for a missing document when the real
problem is a timeout, or the reverse. Both were added, ranked above NOT ENOUGH
DATA in the worst-case ordering, and `CertaintyReport` now separates what the
user can act on from what needs an operator.

Unverified rates and an unavailable KSeF were reclassified from NOT ENOUGH DATA
to **BLOCKED**, which is what they always were.

### §10 — three engine-owned fields were not actually protected

`RESERVED_FOR_ENGINE` was missing:

- `vat_surplus` — a surplus is a claim on the tax office. Reading one off a
  letter would create a refund entitlement out of an interpretation.
- `payment_deadline` and `due_date` — the engine derives these from statute and
  the working-day calendar. A misread digit on a letter would have become the
  date somebody pays by.

All three added, plus `pit_liability`, `vat_payable`, `amount_due`, `tax_rate`,
`filing_deadline`, `advance_due_date`. A companion `EVIDENCE_FIELDS` list makes
the permitted side explicit, and a test asserts no field appears on both lists.

`EngineBoundaryTest` now runs one test per reserved field — 46 tests.

### §16 — statement completeness was not known

"3 transactions imported" was silently readable as "all of August". A statement
covering 1–15 August understates costs exactly as much as importing none.

Statements now record `completeness` (COMPLETE / PARTIAL / UNKNOWN /
OUTSIDE_PERIOD), the covered range, and whether the format supplied balances at
all. UNKNOWN is never treated as complete — a format that states no period
cannot prove it covered one. A month without COMPLETE coverage raises
**BANK DATA INCOMPLETE**.

### §20 — nothing stopped production selecting a fake transport

`TransportGate` now **throws** when a production environment is configured with
the fake transport. Not a warning: a silent fallback would turn "no connection"
into "no invoices found", which is a factual claim about the taxpayer's month.
`TransportGate::reportFailure()` wraps every transport error so it can never be
read as an empty result.

`KSEF_TRANSPORT_ENABLED=false` and `KSEF_TRANSPORT=disabled` are the defaults.

### §22 — the UI did not label unavailable integrations

The dashboard now carries a status panel:

```
KSeF                          NOT CONNECTED
Import wyciągów bankowych     AVAILABLE
OCR / odczyt tekstu           NOT AVAILABLE
Stawki podatkowe              NOT VERIFIED
Wysyłka do organów            DISABLED
```

Every non-operational row states what it means *and* the next step. The KSeF row
says in as many words that this does not mean there are no invoices.

---

## What is not claimed

Per §23, none of the following is claimed anywhere in this repository, and the
tests keep it that way:

- automatic KSeF download — **no HTTP transport exists**
- automatic OCR — **no extractor is installed**
- automatic government filing — every channel throws
- automatic tax payment — never implemented
- "fully autonomous accountant"
- "filing-ready" — official rate verification is incomplete

---

## The KSeF go-live checklist (§1)

Do **not** implement the client from remembered documentation. When official
HTTP access exists:

1. obtain the current official API specification
2. pin the API version
3. implement the transport
4. test authentication
5. test InvoiceRead authorization
6. test invoice retrieval
7. test pagination/cursors
8. test retries/timeouts
9. test duplicate delivery
10. test malformed responses
11. test revoked/expired credentials
12. test API version changes

Only then set `KSEF_TRANSPORT=real` and `KSEF_TRANSPORT_ENABLED=true`.

## The cursor contract (§2)

```
request page → validate → persist all invoices → commit → advance cursor
anything fails → rollback → cursor does NOT advance
```

Invoice identity is protected by a database uniqueness constraint, not by
application-level "have we imported this?" logic — because the logic that should
prevent a double import is exactly what fails during a retry after a timeout. A
timeout after the server accepted the request is therefore safe.

---

## Release order (§25)

**P0** — official Polish rate verification · full regression suite · backup and
restore test · security audit · monthly report verification · historical
reproducibility verification.

**P1** — real KSeF HTTP transport · real OCR · bank reconciliation hardening ·
government inbox.

**P2** — KSeF incremental sync · automatic monthly close · notifications · more
bank formats · accountant export.

**Later** — KSeF submission · JPK · controlled government filing.

The next objective is not more AI. It is: **connect the real official sources,
verify them, and prove the end-to-end workflow with real documents.**

---

## Test coverage of the audit's own requirements

| Suite | Tests | Covers |
|---|---|---|
| `EngineBoundaryTest` | 46 | §10 — one test per reserved field |
| `CertaintyTest` | 20 | §12, §13 — worst-case, six distinct states |
| `ValueComparisonTest` | 19 | §8 — decimal scale, date vs datetime, timezone, equivalence |
| `RateTableTest` | 17 | rate provenance and refusal |
| `ZusCalculatorTest` | 17 | ZUS edge cases |
| `ReconciliationTest` | 16 | §6, §7 — fingerprint, matching, statement formats |
| `GovernmentInboxTest` | 15 | §5, §9, §11 — OCR refusal, AI boundary, disagreement |
| `FilingLifecycleTest` | 14 | §19 — calculation / preparation / filing |
| `KasaFiskalnaReportTest` | 14 | the core monthly workflow |
| `SyncSafetyTest` | 13 | §2 cursor safety, §7 lifecycle |
| `PitCalculatorTest` | 13 | PIT across all three regimes |
| `HistoricalReproducibilityTest` | 12 | §15 — historical rates and reproducibility |
| `AccountantReportTest` | 12 | §14 — report and payment checklist |
| `IntegrationStatusTest` | 12 | §20, §22 — transport gate, status labelling |
| `CredentialSecurityTest` | 11 | §4 — all ten token-security properties |
| `VatCalculatorTest` | 10 | VAT, surplus, exemption limit |
| `FaInvoiceParserTest` | 7 | §3 — schema tolerance, missing fields |
| `MoneyTest` | 7 | exact decimal arithmetic |
| `PeriodTest` | 7 | period arithmetic |
| `TaxProfileTest` | 7 | profile validation |

**Total: 289 tests, 790 assertions.** Green on PHP 8.2, 8.3, 8.4 and 8.5.

## Verified by execution, not by reading

The full stack was run on PHP 8.5.0 against the Liberu ERP (Laravel 13.29.0),
296 migrations applied:

- token stored as ciphertext, absent from `toArray()`, JSON and debug output;
  `InvoiceWrite` refused even when the database row is edited to request it
- unconfigured KSeF client refuses; the refusal says it does not mean there are
  no invoices
- two invoices imported, correction flagged for review, second sync detected
  both as duplicates, original XML proven immutable
- a new invoice after a generated report raised REQUIRES REVIEW with the report
  left at v1
- three statement formats imported; the same payment from two formats flagged as
  a possible duplicate and excluded from reconciliation
- month close reported **BLOCKED** with four named caveats and per-step outcomes
- `GET /poland` renders the integration panel showing NOT CONNECTED,
  NOT AVAILABLE, DISABLED, NOT VERIFIED and AVAILABLE with a next step on each
