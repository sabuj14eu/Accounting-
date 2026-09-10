# Automation: KSeF, bank, government inbox, reconciliation

The system now takes in documents from three sources, reconciles them, and
reports how far the result can be trusted. The deterministic tax engine remains
the only thing that computes tax.

```
KSeF ─────────────┐
Bank statements ──┼──▶ Reconciliation ──▶ Accounting engine ──▶ Monthly report
Government PDFs ──┘                                             Payment checklist
```

## What is built and tested, and what is not

| Piece | State |
|---|---|
| FA invoice XML parser | **Built, tested.** Namespace-agnostic, reads FA(1)/(2)/(3) and an unreleased schema version |
| KSeF ingest: dedup, incremental cursor, immutable XML, REQUIRES REVIEW propagation | **Built, executed end to end** |
| Encrypted token storage, InvoiceRead-only scope | **Built, tested** — ciphertext at rest, redacted everywhere, `InvoiceWrite` refused |
| KSeF HTTP transport | **NOT built.** `ksef.mf.gov.pl` is unreachable from the build environment (blocked at the gateway), so no client could be written against the real API or tested against it. `UnconfiguredKsefClient` refuses until one exists |
| Bank import: CSV, MT940, camt.053 | **Built, tested** against real-format fixtures, with balance reconciliation |
| Bank import: PDF | **Refuses by design** — see below |
| Classification and matching | **Built, tested** — 16 tests |
| Government inbox and classification | **Built, tested** — 15 tests |
| OCR / text extraction | **NOT built.** No toolchain available; the port refuses |
| Certainty states and caveats | **Built, tested** |
| Month close, ten steps | **Built, executed end to end** |
| FA line items, payment terms, correction reference | **Built, tested** (stage B) |
| Review inbox → approval → posting + optional stock, one transaction | **Built, unit-tested; Laravel layer not yet executed against a database** (stage B) |
| Optional per-product inventory (counts, implied consumption) | **Built, unit-tested** (stage B) |
| Glovo settlement as an accounting source; sales channels | **Built, unit-tested** (stage B) |

## Everything that cannot be done, refuses

This is the design rule, applied without exception. Each of these throws rather
than returning a plausible empty value:

- **KSeF not configured** → refuses. An empty invoice list is indistinguishable
  from "you had no purchases this month", and that reading understates costs.
- **No OCR** → refuses. Empty text classifies as INFORMATION ONLY, and a demand
  for payment would sit in the inbox until the deadline passed.
- **PDF bank statement** → refuses, and names the formats that can be read
  exactly. A PDF has no machine-readable structure; one column misread by a
  position turns a 1 234,56 debit into a 123 456 credit that gets booked.
- **No exchange rate** → refuses. A missing rate is not 1.0 and is not
  yesterday's rate.
- **Unverified tax rates** → refuses when `POLAND_REQUIRE_OFFICIAL_RATES=true`.

## KSeF

**InvoiceRead only.** `KsefScope::allowed()` returns exactly one scope. The write
scopes exist in the enum solely so a refusal can name them — granting one is a
code change with a reason, never a config edit. `KsefCredentialModel::revealToken()`
re-checks the scope before handing out a token, so a row edited in the database
to say `InvoiceWrite` still cannot be used.

**The ordinary KSeF password is never stored.** Authentication uses a token the
taxpayer generates in KSeF for this application and can revoke on its own.

**Token handling.** Encrypted at rest by the model cast; excluded from
`toArray()`, JSON and `__debugInfo()`; `KsefSession` redacts it in `__toString`
and print_r. The one way to obtain it is `reveal()` — a single greppable call
site. A test asserts the token appears in none of those.

**Incremental retrieval.** `pl_ksef_sync_state` holds a high-water mark and a
cursor. The mark advances **only on a complete run** — advancing it after a
partial one would skip whatever was not reached. An interrupted sync reports
where to resume and is never reported as clean.

**Duplicates.** A unique index on `(tax_profile_id, ksef_number)`. Not
application logic — the logic that should prevent a double import is exactly
what fails during a retry after a timeout.

**Never overwritten.** `original_xml` and `ksef_number` are immutable at the
model level. KSeF is the document of record; an invoice edited here would stop
matching the authority's copy.

**A new invoice never rewrites a finished report.** It marks the month
REQUIRES REVIEW, records an audit event, and leaves the report at the version
it was. Verified end to end: report stayed at v1, the flag was raised.

## Bank statements

CSV, MT940 and camt.053 are parsed exactly. Two things make the import
trustworthy rather than merely functional:

**Balances are checked.** MT940 and camt carry opening and closing balances, so
the import can prove no row was dropped. A statement that does not balance is
marked untrustworthy and downgrades the month.

**Unreadable rows are reported, not skipped.** A dropped row understates costs
and nothing downstream can notice, so the CSV parser records the line number and
its content, and the statement is not trustworthy until somebody looks.

**Two kinds of duplicate, handled differently.** The file checksum catches
re-uploading the same export outright. The harder case is the same transaction
arriving in two formats — MT940 supplies a counterparty account that camt omits,
so the fingerprints differ and the month's costs would double. Loosening the
fingerprint would be worse: two genuine same-day payments of the same amount
would silently collapse into one. So the second row is imported, **flagged as a
possible duplicate, excluded from reconciliation**, and a person decides. Found
by running both formats through the pipeline.

## Matching

Deterministic. Every point of confidence comes from a stated, checkable fact,
and every fact appears in the reason — because a review queue built on a score
nobody can interrogate gets either rubber-stamped or ignored.

- **MATCHED** requires the amount to the grosz **and** an identifying reference
  in the payment title. Amount alone caps at 0.75 and never auto-books: two
  invoices for the same round sum in one month are ordinary, and picking one by
  amount is a coin toss dressed up as a decision.
- **POSSIBLE** needs human approval.
- **NEEDS REVIEW** is never booked.

References are compared with separators stripped, so `FV 2026 08 417` matches
`FV/2026/08/417`. Direction must agree before anything else: money arriving
cannot be the payment of an invoice you owe. One document cannot be claimed by
two transactions — the second goes to review.

A decision a person has made is never overwritten by a later rerun.

## The AI boundary

`InterpretationBoundary` enforces it in code rather than trusting review.

An interpreter may read, extract, classify, summarise, match, suggest and
explain. It may **never originate a tax liability**. `RESERVED_FOR_ENGINE` lists
the fields that would be computing tax — `zus_total`, `pit_due`, `vat_due`,
`tax_base`, `rate` and the rest — and any suggestion targeting one throws.

The distinction that makes this workable: `stated_amount` ("the letter says
2 757,34") is a fact about the letter and is allowed. `zus_total` ("you owe
2 757,34") is a tax conclusion and is not.

Where a document states an amount the engine also computes,
`InterpretationBoundary::compare()` reports AGREES or **MANUAL REVIEW
REQUIRED** — never resolving the difference in favour of either side.

`isActionable()` requires **every** suggestion to clear the confidence bar, not
the average: one uncertain field in an otherwise confident extraction is exactly
the case that needs a human.

## Certainty

Four states, and combining them takes the **worst**, never an average — a report
is exactly as trustworthy as its least trustworthy input, and averaging would let
one missing statement disappear behind nine good ones.

| State | Meaning |
|---|---|
| `VERIFIED` | Reconciled against an independent source. The only state that is final |
| `CALCULATED` | Arithmetic is right. Says nothing about completeness |
| `REQUIRES REVIEW` | Something arrived or changed after this was produced |
| `NOT ENOUGH DATA` | A source needed for this figure is missing |

Getting the arithmetic right is not the same as having checked it, so a clean
month with no bank statement is CALCULATED, not VERIFIED.

Every caveat carries a remedy. A warning with no way out is a warning people
learn to ignore.

## Month close

Ten steps, in dependency order. **A failing step downgrades certainty; it does
not abort the close** — a month with no KSeF connection still needs its ZUS
figure. Each step records what it could not do and why.

Observed on a real run with a missing rate verification, an unclear letter and
unmatched transactions:

```
ksef            ok     pominięto na żądanie
bank            ok     3 transakcji w okresie, 2 możliwych duplikatów wyłączonych
government      ok     1 nowych pism, 1 wymaga przeglądu
reconciliation  ok     1 dopasowanych, 2 do przeglądu
report          ok     wersja 3, do zapłaty 2 568,11 zł

certainty = NOT ENOUGH DATA
  - possible_duplicate_transactions
  - interpretation_inconclusive
  - unmatched_transactions
  - rates_unverified
```

Nothing is submitted anywhere. No KSeF filing, no JPK, no automatic payment —
every filing channel is still bound to an adapter that throws, and a test
asserts `FilingChannel::automated()` stays false for all of them.

## Before turning KSeF on

1. Implement `KsefClient` against the **current** official API. Do not write it
   from the documentation in this repository — verify the endpoints,
   authentication and schema versions against the Ministry's current
   publication, as `docs/RATE_VERIFICATION.md` requires for rates.
2. Test against the KSeF **test** environment first.
3. Generate a token with **InvoiceRead only**.
4. Set `KSEF_ENABLED=true` and the base URL. Never commit the token.
5. Run one sync over a closed month and compare what arrived against what the
   taxpayer knows they bought.
