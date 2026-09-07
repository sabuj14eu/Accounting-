# What to tell Fable — 2026-09-08

*Paste the block at the bottom of this file into a new window. Everything above
it is the detail behind it.*

---

## 1. What Fable is being handed

**Two applications, one repository, and they must stay separate.**

| | Poland Accounting | Shop Profit Intelligence |
|---|---|---|
| Where | `modules/poland/` + the Liberu foundation | `shop-intelligence/` |
| What it is | the official accounting service — VAT, ZUS, PIT | a management analysis tool for the shop |
| Answers | "what do I have to pay this month?" | "am I actually making money, and where am I losing it?" |
| Status | live application reached; production accounting **not** reached | analysis core built and tested; **no UI, no database, not deployed** |
| Tests | 309 / 874 assertions, 0 failures | 60 / 469 assertions, 0 failures |

They share **nothing**: no database, no table, no session, no credential, no
code, no network path. Each works when the other is offline. That is checked
mechanically by `shop-intelligence/bin/check-shop-isolation.sh` (10 checks,
currently 0 violations) and by `IsolationTest`.

Everything is on branch `claude/poland-accounting-app-pijnfm`.

---

## 2. The laws that must not be weakened

These came from the user's own specifications. Fable may extend them; Fable must
not soften them to make a feature list look complete.

**From the Shop Intelligence specification:**

1. This application is an **analysis and management tool only. It is NOT the
   official accounting service.** It never calculates official tax, never files
   anything, never touches KSeF credentials, never writes to the accounting
   system.
2. **Never present an estimate as an official accounting result.**
3. Four provenance classes, always distinguishable: **OFFICIAL ACCOUNTING FACT ·
   ANALYTICAL ESTIMATE · USER DECLARATION · AI SUGGESTION.**
4. **Never mix ACTUAL with EXPECTED with ESTIMATED with USER DECLARED without
   displaying the distinction.**
5. The worked example, which is the whole design in one sentence: a 5 000 zł
   invoice settled 3 000 by bank and 2 000 by declared cash is FULLY ALLOCATED —
   **but the system must NOT pretend it found the 2 000 in the bank.**
6. **Never assume theft.** A cash difference, a stock difference and a platform
   shortfall are all REQUIRES REVIEW with innocent explanations listed. Never an
   accusation, never a name.
7. **Never silently overwrite history.** A correction is a new entry that points
   at the original; both stay.
8. AI may read, extract, classify, suggest matches, identify anomalies, explain
   and summarise. AI must **not** silently change transactions or inventory,
   invent cash payments, revenue or expenses, declare an invoice paid, change
   accounting records, calculate official tax, submit to government, access KSeF
   credentials, or modify the accounts system. All eleven prohibitions are in
   `Shop\Ai\AiAction` and all eleven are tested.
9. **Do not overbuild.** Three pages. Not another ERP.

**From the accounting side, and they still hold:**

10. A calculation is not a filing. `filed_at` is set by a successful submission
    that returned a reference, and by nothing else.
11. Rates are data, never code. Refuse rather than extrapolate.
12. **Absence is not zero.** No costs recorded ≠ no costs incurred. No count
    taken ≠ nothing missing. This is why `Total::of([])` is NO DATA and not
    ACTUAL zero.
13. **Missing data is not zero data**, and the existing fail-closed behaviour is
    a feature. **Do not replace refusing adapters with mocked or silent
    implementations to make the application appear complete.**

---

## 3. What exists in `shop-intelligence/` right now

A framework-free analysis core in `src/`, `Shop\` namespace. No Composer
install, no database, no Laravel — `php bin/shop-demo` renders a complete worked
month with none of them.

**The design decision underneath everything:** a number carries its evidence in
the type system, not in a label beside it on screen. `EvidenceType` decides
`Provenance` and `Certainty`, and nothing lets a caller set them.

| Area | Classes | The rule it enforces |
|---|---|---|
| Truth | `Money`, `Provenance`, `Certainty`, `EvidenceType`, `Figure`, `Total`, `ReviewFlag` | worst-case combination; a mixed total cannot be rendered without its split; a flag cannot accuse or exist without innocent explanations |
| Page 1 | `AllocationSet`, `AllocationState`, `MatchHistory`, `MatchRevision`, `PlatformSettlement` | bank-confirmed and declared stay distinguishable forever; corrections append; `gross − commission − fees` against what the bank received |
| Page 2 | `Quantity`, `Recipe`, `RecipeBook`, `TheoreticalConsumption`, `StockLine`, `StockReconciliation` | `opening + purchases − theoretical = expected` vs counted; units cannot be mixed; a product with no recipe consumes UNKNOWN, not zero |
| Page 3 | `CostCategory`, `CostEntryType`, `CostEntry`, `RevenueChannel`, `ChannelResult`, `ProfitStatement`, `ProductProfitability`, `PriceReview`, `CashLedger` | recurring costs are EXPECTED until a bank line or invoice confirms them; management profit inherits the worst certainty; the price review has no method that changes a price |
| Boundaries | `AiAction`, `AiBoundary`, `PendingSuggestion`, `AccountsSnapshot`, `AccountsComparison` | the eleven AI prohibitions; the only route in from the accounts is a hand-imported snapshot; no write path back |
| Output | `ShopMonitor`, `Baseline`, `LossRanking`, `MonthlyManagementReport`, `TextReportRenderer` | three data points are not a trend; the worklist is ranked by money at stake; notes are not ranked as losses |

**Measured 2026-09-07, PHP 8.4.19: 60 tests, 469 assertions, 0 failures,
0 errors, 0 skipped.** `failOnWarning` and `failOnRisky` are both on.
Isolation guard: 10 checks, 0 violations.

Read in this order: `shop-intelligence/README.md` →
`shop-intelligence/docs/BANKING_UX.md` → `docs/ISOLATION.md` →
`docs/REGRESSION_MAP.md`.

---

## 4. What does NOT exist — say this plainly, do not let it be discovered

The analysis core is real and tested. **Everything around it is not built.**

1. **No user interface.** Not one screen. The three pages exist as a design in
   `docs/BANKING_UX.md` and as a text renderer, nothing else.
2. **No database and no migrations.** Nothing persists. Every object in the core
   is constructed in memory.
3. **No authentication, no users, no sessions.** `.env.example` describes what
   they must look like; none of it is implemented.
4. **No importers.** No bank statement import, no card terminal report, no
   Glovo or Uber Eats statement parsing, no supplier invoice reading, no OCR.
   Every one of those is currently a human typing figures into a constructor.
5. **No month close, no snapshot, no immutability in storage.** The rule is
   written down; there is no table to enforce it in.
6. **Not deployed.** No database created, no vhost, no systemd unit, no backup.
   `docs/ISOLATION.md` §"still deployment work" says so.
7. **The 27 regression tests were derived from the specification body, not
   transcribed from its §29 list.** Fable must read §29 line by line against
   `docs/REGRESSION_MAP.md` and report which items are missing. Overlap is not
   the same as coverage.

---

## 5. Where I am most likely to be wrong

Check these before building on top of them.

- **Every threshold is invented.** 65% target margin, 2% stock tolerance, −15%
  revenue alert, 20% cost alert, 5-point margin drop, 1% platform-payout
  tolerance, 3-period baseline minimum. None came from this shop's data. They
  are defaults in `.env.example` and they should be set from the shop's actual
  history, or left explicitly unset.
- **The accusation word list in `ReviewFlag` is incomplete.** It catches the
  obvious English and Polish words. It will not catch a phrasing that accuses
  without a keyword. Extend it, and treat the review text as something a person
  will read at 11 p.m. about a colleague.
- **`CASH_COUNTED` is classified as ACTUAL but not independently corroborated.**
  That is a judgement: the money is real, but the only witness is the person
  counting. If Fable disagrees, the change belongs in `EvidenceType`, once, not
  scattered across call sites.
- **`Baseline` uses a median over three or more periods.** For a seasonal food
  shop that may be exactly wrong — August against a median of May, June, July is
  comparing a holiday month to term time. Consider same-month-last-year, and
  say so on screen when the comparison is not like-for-like.
- **The demo figures in `bin/shop-demo` are invented.** They are there to show
  the labelling, not the business. Do not quote them at the user as findings.
- **Contribution is not profit, and the naming is load-bearing.**
  `ChannelResult::contribution()` carries no rent, wages or electricity. If a
  screen ever calls it "Glovo profit", the user will make a wrong decision from
  a right number.

---

## 6. The work order

In this order, because each one makes the next honest.

**P0 — make it real without weakening it.**

1. Schema and migrations under `shop_intelligence`, its own user, its own
   grants. Database-level dedup: unique index on each import's natural
   fingerprint, plus a `possible_duplicate_of` column for near-matches — the
   accounting side lost a day to a cross-format duplicate (MT940 vs camt.053)
   that would have doubled a month's costs, and the fix that held was an index,
   not application logic.
2. Its own authentication, its own session cookie on its own domain. **No shared
   login with the accounting application** — that is §2, not a preference.
3. Immutable month close: a versioned snapshot with the figures, the certainty
   labels, the flags and the code version. A later correction supersedes and
   states why; the superseded version stays readable.
4. Re-run the isolation guard after each of the three, not at the end.

**P1 — the three pages**, exactly three, built to `docs/BANKING_UX.md`: ledger
first with running balances, every figure drilling to its source document, the
four certainty badges on every amount, corrections as new rows, and two profit
figures (confirmed / projected) side by side rather than one with a footnote.

**P2 — the importers**, each one fail-closed like the accounting side's: a
parser that cannot read a file **refuses and says so**, and never returns an
empty result that reads as "no transactions". Bank statement first (CSV, MT940,
camt.053 — the accounting module has working parsers to learn from, but copy the
approach, not the code: these are separate applications). Then card terminal,
then Glovo and Uber Eats statements. OCR last.

**P3 — the honest extras:** same-month-last-year baselines, thresholds set from
real history, the recipe book filled in from the actual kitchen.

---

## 7. Carried over, still open, from the accounting application

These are in `docs/OPEN_ITEMS.md` and they have not moved.

- **The Polish rate tables are unverified.** Every version is marked
  `secondary`. Verification from the build environment is impossible — zus.pl,
  gov.pl, isap.sejm.gov.pl and stat.gov.pl are all refused by the network
  policy. It needs a human with network access. Most critical single item:
  whether the 2026 health-contribution reform really did not take effect. If the
  secondary sources are wrong, every 2026 health figure is wrong.
- **The real-data pilot (release gate 12) is `REQUIRES HUMAN`.**
- KSeF HTTP transport, the OCR toolchain, and the upstream Liberu index-name bug
  to report.

Milestones, stated precisely because the user asked for them to be:
**LIVE APPLICATION reached. PRODUCTION ACCOUNTING not reached** (needs rate
verification and the pilot). **AUTOMATED FILING not reached and deliberately
disabled.** Shop Intelligence is at none of these — it is pre-deployment.

---

## 8. Paste this into Fable's window

> You are picking up two separate applications in the repo `sabuj14eu/Accounting-`,
> branch `claude/poland-accounting-app-pijnfm`.
>
> Read first, in this order: `CLAUDE.md`, `docs/FABLE_BRIEFING_2026-09-08.md`,
> `shop-intelligence/README.md`, `shop-intelligence/docs/BANKING_UX.md`,
> `shop-intelligence/docs/ISOLATION.md`,
> `shop-intelligence/docs/REGRESSION_MAP.md`, `docs/OPEN_ITEMS.md`.
>
> **Poland Accounting** (`modules/poland/`) is the official accounting service.
> It is live but not production-ready: the rate tables are unverified and the
> real-data pilot has not been done. Do not mark it production ready.
>
> **Shop Profit Intelligence** (`shop-intelligence/`) is a separate management
> analysis tool. Its analysis core is built and tested — 60 tests, 469
> assertions, 0 failures. It has **no UI, no database, no authentication, no
> importers and is not deployed**. That is the work.
>
> The two applications share no database, no table, no session, no credential
> and no code, and each must keep working when the other is offline. Prove it
> after every change with `shop-intelligence/bin/check-shop-isolation.sh`.
> Neither application touches the trading system in any way.
>
> Rules I need you to hold to rather than optimise around:
> - Shop Intelligence is analysis only. It never calculates official tax, never
>   files anything, never touches KSeF, never writes to the accounting system.
> - Never present an estimate as an official accounting result, and never mix
>   ACTUAL / EXPECTED / ESTIMATED / USER DECLARED without showing the split.
> - A 5 000 invoice settled 3 000 by bank and 2 000 by declared cash is fully
>   allocated — but never show it as 5 000 found in the bank.
> - A cash, stock or payout difference is REQUIRES REVIEW with innocent
>   explanations. Never assume theft, never name anyone.
> - Corrections are new entries. Nothing is silently overwritten.
> - AI may read, extract, classify, suggest, explain, summarise. It may not
>   change data, invent payments, mark anything paid, calculate tax, file
>   anything, or touch credentials.
> - Fail-closed behaviour is deliberate. Do not replace a refusing adapter with
>   a silent one to make the feature list look complete.
> - Three pages. Do not turn this into another ERP.
>
> **First task:** audit what is there. Read §29 of the Shop Profit Intelligence
> specification line by line against `shop-intelligence/docs/REGRESSION_MAP.md`
> and tell me which of its 27 required tests are actually missing — the existing
> 27 were derived from the specification body, not transcribed from §29. Then
> check §5 of the briefing, "where I am most likely to be wrong", and tell me
> which of those you disagree with.
>
> **Then build, in this order:** the database and migrations under their own
> user; separate authentication; immutable month close; then the three pages to
> `docs/BANKING_UX.md`; then the importers, each fail-closed.
>
> Run `../modules/poland/vendor/bin/phpunit -c phpunit.xml` and
> `./bin/check-shop-isolation.sh` from `shop-intelligence/` before every commit.
> Report measured numbers, never estimates.
