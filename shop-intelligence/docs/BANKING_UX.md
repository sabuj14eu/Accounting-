# "It should work like a banking app"

That instruction is the most useful sentence in the whole specification, because
a banking app is not a *style*. It is a set of hard promises a bank makes about
how it shows money, every one of which maps onto a rule this application already
has to follow. This document turns the instruction into the design.

Ten promises, what they mean here, and how they change the three pages.

---

## 1. The ledger is the product. The dashboard is derived from it.

Open any bank app and the first screen is a **list of movements with a running
balance**. Charts and summaries live behind it. Nobody trusts a bank that leads
with a pie chart.

So the primary object on every page here is a **row**, not a KPI:

| Page | The ledger |
|---|---|
| 1 — Money | every payment in and out, with a running balance per account |
| 2 — Stock | every ingredient movement, with a running quantity |
| 3 — Profit | every cost and revenue line, with a running result |

`CashLedger::statement()` already returns exactly this shape: movements sorted
by date, each with the balance *after* it. A single closing figure tells you
that something is wrong; only a running balance tells you **when** it went
wrong, and "when" is the only question that leads to an answer.

Summary tiles stay — but each tile is a link into the rows that produced it,
never a number that exists on its own.

## 2. Pending and settled are never added together silently

A bank shows you two balances: **book balance** and **available balance**. A
card authorisation that has not settled appears separately, greyed, and it does
not quietly inflate what you think you have.

That is `Certainty::ACTUAL` versus `Certainty::EXPECTED`, and it is why
`Total::describe()` cannot render a mixed figure without its split:

```
Costs : 56 300,00 zł [ESTIMATED] — mixed: ACTUAL 48 400,00 zł
                                        + EXPECTED  6 000,00 zł
                                        + ESTIMATED 1 900,00 zł
```

**Page 3 therefore shows two profit figures, always, side by side:**

- **Confirmed result** — built only from ACTUAL evidence. The bank's "available
  balance".
- **Projected result** — including EXPECTED recurring costs and ESTIMATED
  packaging. The "book balance".

Never one number with a footnote. Two numbers, both large, both labelled.

## 3. A posted entry is never edited. A correction is a new entry.

Banks do not rub out a transaction. They post a reversal and both stay visible
forever, because the audit trail is the product.

`MatchHistory` is built this way: `open()` writes revision 1, `correct()`
appends revision 2 with who, when and *why the earlier one was wrong*, and there
is no `delete()` and no setter. The screen shows the current match with a small
**"corrected"** marker; tapping it shows the whole chain.

The same applies to a cash count, a stock count and a cost entry. **Correcting
is an action that adds a row.**

## 4. A statement period is closed, and a closed period does not move

Your August bank statement says the same thing in October as it did in
September. That is what makes it usable as evidence.

**Month close writes an immutable snapshot**: every figure, every certainty
label, every flag, the code version and the timestamp. Later data does not
rewrite it — it produces a **new version** that supersedes it and says what
changed and why, with the superseded version still readable. Exactly the rule
the accounting side already applies to a corrected cash-register month.

Without this, "revenue was 73 500" is a claim with no date attached, and the
first time a late Glovo statement lands the number silently becomes something
else.

## 5. Two dates, not one

Banks show **transaction date** and **value date**, because they differ and the
difference matters. Here they differ constantly:

- a Glovo payout for August arrives on 5 September;
- an invoice dated 30 August is paid on 12 September;
- a stock count "for August" is taken on the morning of 1 September.

Every row carries **the date it happened** and **the date it was recorded or
cleared**, and the period filter uses the first. This alone explains most of the
gap between this application and the official accounts, and §27 requires that
gap to be explainable rather than merely visible.

## 6. Every figure drills down to a document

In a bank app you tap any line and reach the counterparty, the reference, the
card, the exact time. Nothing is a dead end.

The rule here: **no figure without a reference.** `Figure` takes a
`sourceReference`, `CostEntry` takes a document number, `MatchRevision` names
the bank line. Tapping the food-cost figure lands on the invoices; tapping an
invoice lands on its allocations; tapping an allocation lands on the bank line
or on the declaration, *with the name of whoever declared it*.

A figure the owner cannot trace back is a figure they have to take on faith, and
this is not a good place for faith.

## 7. A small, fixed vocabulary of statuses

Banks use about five words, and customers learn them. So does this:

| Chip | Meaning | Where |
|---|---|---|
| `FULLY ALLOCATED` | nothing outstanding on this document | page 1 |
| `PARTIALLY ALLOCATED` | some of it is still open | page 1 |
| `UNRECONCILED` | small unexplained difference | page 1 |
| `REQUIRES REVIEW` | a difference a person should look at | all three |
| `NOT COUNTED` / `NO DATA` | nobody has checked; **not** the same as zero | pages 2, 3 |

Plus one badge on every amount: `ACTUAL` · `EXPECTED` · `ESTIMATED` ·
`USER DECLARED`. Four words, one colour each, used on every screen. A fifth word
means the design has drifted.

## 8. Nothing is red without an explanation you can open

A bank that flags a transaction tells you what to do about it. A red number that
does nothing but worry the owner is a design failure — and, in this application,
worse than that: the difference between a shortfall and an accusation is
entirely in how it is presented at 11 p.m. by a tired person.

`ReviewFlag` is the mechanism: it **cannot be constructed** without innocent
explanations, and it **refuses accusatory vocabulary** in English and Polish. So
every red thing on every screen opens into "here is the difference, here is what
usually causes it, here is what it is worth". Never "here is what somebody did".

## 9. Imports are idempotent, and the same money is never booked twice

A bank never books a transaction twice. Importing the same statement twice must
be a no-op, and importing an overlapping statement must recognise the overlap.

The accounting application learned this the hard way — the cross-format
duplicate (MT940 against camt.053) that would have doubled a month's costs. The
same defence applies here, at the **database level and not in application
logic**: a unique index on the natural fingerprint, and a `possible_duplicate_of`
column for near-matches that get flagged rather than dropped.

`AllocationSet` already catches the visible half of this: the same transfer
allocated twice makes an invoice `OVER_ALLOCATED` rather than quietly
double-settled.

## 10. Money actions are signed, session-scoped, and logged

Its own login. Its own session cookie on its own domain. Its own timeout. A
count that names its counter (`CashLedger` refuses an anonymous one). An audit
row for every change: who, what, when, from what to what, why.

And the negative space, which is just as much part of a banking app's promise:
**this application initiates nothing.** No payment, no filing, no price change,
no write to the accounting system. `PriceReview` has no method that returns a
new price. `AccountsComparison` has no method that writes. A regression test
asserts the absence of both.

---

## What this changes about the three pages

**Page 1 — Money.** Two panes. Left: the bank/card/cash ledger with running
balances and a period selector. Right: documents with their allocation state.
Selecting a bank line highlights its candidate documents with a confidence
score and the reason; accepting writes a revision, never an edit. Platform
payouts sit at the top as their own reconciliation strip: `gross − commission −
fees = expected` against `received`, with the difference and its status.

**Page 2 — Stock.** One table, one row per ingredient, columns exactly as the
formula reads: `opening + purchases − theoretical = expected` versus `counted` →
`difference` → status. The formula *is* the header row, so the arithmetic is
visible rather than hidden behind a total. Uncounted ingredients stay in the
table as `NOT COUNTED`, never dropped, and the page says whether the check was
COMPLETE or PARTIAL. Food cost shows theoretical and actual side by side; the
gap between them is the number the owner came for.

**Page 3 — Profit.** Confirmed result and projected result, side by side, both
large. Under them the three cost groups, each expandable to its subcategories
and then to its documents. Then channels ranked by contribution margin, with the
warning that contribution is not profit. Then products, with the 🟢/🟡/🔴 review
and its explanation. Then, at the bottom and impossible to miss, **"Where am I
losing money?"** — ranked by money at stake, each item opening into its
explanations. Notes about how to read a figure are listed separately and are
never ranked as losses, because inflating the worklist is its own kind of
dishonesty.

---

## The one thing a banking app does that this must NOT copy

A bank app is confident. It shows a balance to the grosz and never says "we are
not sure". It can afford that: it *is* the system of record, and the number it
shows is true by definition.

This application is not the system of record for anything. Its inputs are a
cash-register total, an owner's memory of a cash payment, a recipe that
approximates a kitchen, and a platform statement that may be revised. Copying a
bank's confidence would be copying the one property it has that this application
has no right to.

So: **the layout, the ledger, the drill-down, the immutability and the audit
trail from a banking app — and none of its certainty.** Every figure keeps its
evidence attached, and the honest answers (`NOT COUNTED`, `NO DATA`,
`REQUIRES REVIEW`, `CANNOT SEPARATE`) are first-class results rather than
failures to be tidied away.

Which is really the same rule the rest of this system already runs on:
*never make the system look more certain than the underlying data.*
