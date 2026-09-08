# Specification audit — 2026-09-08

What exists in `shop-intelligence/` measured against the Shop Profit
Intelligence specification and the briefing of 2026-09-08. Every verdict below
names the file, the test or the command that produced it. Verdicts are
PASS · PARTIAL · NOT IMPLEMENTED · NOT TESTED · REQUIRES HUMAN. "Documented"
is never a verdict: a rule written in `BANKING_UX.md` and enforced nowhere is
NOT IMPLEMENTED, however well it is written.

Measured on PHP 8.4.19, commit on branch `claude/regression-map-audit-lis0w8`.

| Check | Before this audit | After this audit |
|---|---|---|
| `vendor/bin/phpunit -c phpunit.xml` | 60 tests, 469 assertions, 0 failures | **72 tests, 545 assertions, 0 failures, 0 errors, 0 skipped** |
| `./bin/check-shop-isolation.sh` | 10 checks, 0 violations | **10 checks, 0 violations** (now also scans `deploy/`) |
| `../bin/check-isolation.sh` (trading) | 4 checks pass | **4 checks pass** |
| `php bin/shop-demo` | renders | renders, with CONFIRMED and PROJECTED results side by side |
| Shop Intelligence in CI | **not run** — `ci.yml` had no job for it, although `ISOLATION.md` said "both run in CI" | job added: guard + suite on PHP 8.2 / 8.4 / 8.5 + demo |

---

## 1. §4 — what does NOT exist, confirmed by looking rather than reading

Each row was checked against the tree, not against the documentation.

| Claimed absent | How it was checked | Verdict |
|---|---|---|
| User interface | `find src -name '*.blade.php' -o -name '*.html' -o -name '*Controller*'` → nothing. The only output path is `Reporting/TextReportRenderer.php` (plain text). | **NOT IMPLEMENTED** |
| Database | `IsolationTest::test_the_analysis_core_opens_no_database_connection` passes: no PDO, no Eloquent, no `DB::`. No `database/` directory. | **NOT IMPLEMENTED** |
| Migrations | No `migrations/` anywhere under `shop-intelligence/`. No `shop_*` table is defined by any file. | **NOT IMPLEMENTED** |
| Authentication | No user, session, password or login class. `.env.example` names a cookie and a session driver; nothing reads `.env` (`grep -rn getenv src` → nothing). | **NOT IMPLEMENTED** |
| Importers | `grep -rniE 'csv|mt940|camt|ocr|parse(File|Statement)' src` → nothing. Every figure enters through a constructor argument. | **NOT IMPLEMENTED** |
| Month close / snapshot storage | No class whose name or method contains `close`, `snapshot` (other than the hand-imported `AccountsSnapshot`), `version` or `supersede` on a period. A period is a string. | **NOT IMPLEMENTED** |
| Deployment | Before this audit: no vhost, no unit, no script. After it: `deploy/nginx/shop.signalmesh.dev.conf` and `bin/prepare-server.sh` exist **in the repository**; nothing has been run on the server, and this container cannot reach it. | **NOT DEPLOYED** |

Nothing in this table is inferred from `README.md`, `BANKING_UX.md` or the
briefing. Where those documents describe one of these as a design, the design
is real and the implementation is absent.

---

## 2. §29 line by line against `REGRESSION_MAP.md`

The 27 tests in the map were derived from the specification body. This table
takes the §29 list as given (eight requirements) and asks two separate
questions of each: is there a test, and does the test prove the requirement
rather than something adjacent to it. **Overlap is not coverage**: R05 proves
match corrections and was being read as proof of "corrections"; R01/R02 prove
the bank-plus-cash split and were being read as proof of "partial payment".
Neither reading survives the second question.

| §29 requirement | Test exists? | Test actually proves it? | Missing |
|---|---|---|---|
| **Partial payment** | ❌ before · ✅ now **R28, R29** | ✅ `PARTIALLY_ALLOCATED` with 2 000 outstanding and no flag; `UNALLOCATED` with the full amount outstanding. Before this audit only FULLY and OVER were asserted; the state the requirement names had no test. | Persistence of a partial payment across a month boundary (no storage). A partial payment reduced by a credit note (no credit-note concept; `allocate()` refuses negatives and says so). |
| **Bank + cash** | ✅ R01, R02 | ✅ 5 000 = 3 000 `BANK_CONFIRMED` + 2 000 `USER_DECLARED`, FULLY ALLOCATED, `corroborated()` 3 000 / `uncorroborated()` 2 000, description names both, flag `SETTLED_PARTLY_ON_DECLARATION`. This is the specification's worked example and the test is a transcription of it. | Nothing in the core. That a *screen* shows it cannot be proven: there is no screen. |
| **Platform reconciliation** | ✅ R07, R08, tolerance test · ✅ now **R31** | ✅ `gross − commission − fees = expected` against received; shortfall → `REQUIRES_REVIEW` with ≥3 innocent explanations and the gap as impact; no receipt → `NO_PAYOUT_RECORDED`, never RECONCILED; within tolerance → RECONCILED. The `UNRECONCILED` branch (small gap) was **untested** until R31, which pins that a gap outside tolerance is never RECONCILED whatever its size. | Gross, commission and fees are bare `Money` with no evidence type and no statement reference — the platform's own figures carry no provenance (see §4 and §5 below). A payout covering two periods. A revised platform statement (no correction chain on `PlatformSettlement`). |
| **Inventory** | ✅ R15–R18, formula test, partial-check test, food-cost test | ✅ `opening + purchases − theoretical = expected` vs counted; `REQUIRES_REVIEW` lists ≥5 innocent causes with portioning first; `NOT_COUNTED` is not a match; units cannot be mixed; a product with no recipe is UNKNOWN not zero; a partial count says PARTIAL. | **Theoretical quantity carries no certainty** — `Quantity` has no `EvidenceType`, so `StockLine::$theoreticalConsumption` and `StockLine::$physicalCount` are the same type and serialise identically (§5 below). A stock count names nobody (`CashLedger` requires `countedBy`, `StockLine` does not — R26 has no stock twin). No correction chain for a count. The 5 % food-cost alert is hard-coded in `StockReconciliation::reviewFlags()` and its boundary is untested. |
| **Rent / utilities** | ✅ R19, R20 | ✅ RECURRING is EXPECTED; `confirmedBy()` with BANK or INVOICE makes it ACTUAL and records the reference; USER_ENTERED cannot confirm it. | A confirmation at a **different amount** from the schedule is accepted with no flag (`confirmedBy(Money $actualAmount, …)` never compares). No due date, so "overdue" cannot exist. The confirmed entry does not point at the EXPECTED entry it replaces: `confirmedBy()` returns a new object and the caller swaps it into the list — an in-memory overwrite of exactly the kind Banking UX §3 forbids. |
| **Corrections** | ✅ R05, R06 (matches) · ✅ now **R33** (recipes) | ✅ for a match: original kept, revision 2 appended with who, when and why; a correction with no reason is refused. ✅ for a recipe: `add()` refuses to overwrite; `revise()` keeps the superseded version. | **Not implemented** for a cash count, a stock count, a cost entry, a platform statement or an accounts snapshot: none of `CashLedger`, `StockLine`, `CostEntry`, `PlatformSettlement`, `AccountsSnapshot` has a `correct()` and none keeps a predecessor. Banking UX §3 promises it for the first three by name. Until then R05 covers one of six correctable things. |
| **Immutability** | ❌ before · ✅ now **R30** | ✅ in memory: every `MatchRevision` property is `readonly` (asserted by reflection), writing one throws `Error`, and neither `MatchHistory` nor `AllocationSet` has a delete/remove/reset/set method (asserted as an absence, like the price-review and comparison tests). | **Storage immutability does not exist** because storage does not exist. Nothing corresponds to the accounting side's `pl_audit_events` refusing UPDATE and DELETE at the model level. R30 proves that objects cannot be altered for the life of one PHP process, which is the strongest claim the current code can support. |
| **Closed month** | ❌ | ❌ | **NOT IMPLEMENTED.** No month-close object, no versioned snapshot, no "superseded by", no code-version stamp, no test. Banking UX §4 describes it exactly; the domain has no type for it. Cannot be tested until it is built, and it should be built in the domain first (a `PeriodClose` value with figures, labels, flags, code version, timestamp and a `supersededBy` chain) and then given a table, so the test does not depend on the database. |

**Count.** Of the eight §29 requirements: three were fully proven in the core
before this audit (bank + cash, platform reconciliation, rent/utilities); two
were partially proven (inventory, corrections); one was testable but had no test
(partial payment — now R28/R29); one was testable only in memory and had no test
(immutability — now R30); one is not implemented (closed month). Eight new tests
(R28–R35) were added; **none of them is a substitute for the storage, the
correction chains or the month close, which remain NOT IMPLEMENTED.**

---

## 3. Banking UX invariants

| Invariant | Enforced by | Verdict |
|---|---|---|
| Ledger is the product | `CashLedger::statement()` returns movements in date order with the balance after each (tested). There is no ledger row type for a bank account, for stock movements or for the profit page: `StockLine` is a period aggregate, `ProfitStatement` a list. | **PARTIAL** — one ledger of three |
| Dashboard is derived | `TextReportRenderer` computes nothing; every line calls a domain method. There is no dashboard to judge. | **NOT TESTABLE** (renderer PASS) |
| Pending and settled never silently combined | `Total::describe()` prints the split in the same method as the amount (R11). But `Total::$amount`, `ProfitStatement::managementProfitAmount()` and `revenueTotal()->amount` are public bare `Money`, used by `AccountsComparison` and `ShopMonitor` for arithmetic; a future template could print them. The rule holds at `describe()`, not at the type. | **PASS in the core, unguarded at the edge** |
| Show the two figures separately | Was **missing in the domain**: there was one result with a footnote flag. Added `confirmedResult()` (ACTUAL only), `projectedResult()`, `unconfirmedPortion()`; renderer prints both, both labelled (R32, renderer test). | **PASS (new)** |
| Posted entries are immutable | R30 in memory. No storage. | **PARTIAL** |
| Corrections create new rows | Matches (R05/R06), recipes (R33). Cash count, stock count, cost entry, platform statement, snapshot: no. | **PARTIAL** |
| Closed months don't move | Nothing. | **NOT IMPLEMENTED** |
| Transaction date and posting/settlement date distinct | `Figure` has one optional `occurredOn`; `CashMovement` one `date`; `CostEntry` only a `period`; `PlatformSettlement` only a `period`; `MatchRevision` only `changedAt`. **No class carries two dates.** The period filter Banking UX §5 describes cannot be built on these types. | **NOT IMPLEMENTED** |
| Every number drills down to its source | `CostEntry` requires a reference (PASS). `Figure::$sourceReference` is **nullable and defaults to null**; the tests and the demo build figures without one. `PlatformSettlement` gross/commission/fees, `ChannelResult`, `ProductProfitability` are bare `Money` with no reference at all. "No figure without a reference" (Banking UX §6) is stated and not enforced. | **PARTIAL** |

---

## 4. Certainty audit — the seven classes

The specification's list, against what the type system can actually say.

| Spec class | In the type system | Distinguishable on a screen? |
|---|---|---|
| DOCUMENT CONFIRMED | `EvidenceType::SUPPLIER_INVOICE`, `FISCAL_REPORT`, `CARD_TERMINAL`, `PLATFORM_STATEMENT` → certainty ACTUAL, corroborated | Only via `EvidenceType` — the badge is ACTUAL, the same as bank |
| BANK CONFIRMED | `EvidenceType::BANK_CONFIRMED` → ACTUAL, corroborated | Same badge as document-confirmed; `Figure::jsonSerialize()` emits `evidence`, so a drill-down can show it |
| USER DECLARED | `EvidenceType::USER_DECLARED` → certainty USER_DECLARED, provenance USER_DECLARATION, not corroborated | Yes, own badge |
| THEORETICAL | **None for quantities.** `Recipe::forPortions()` returns bare `Quantity`; `TheoreticalConsumption::$byIngredient` is `Quantity[]`. For money, folded into `EvidenceType::ESTIMATED` ("computed from a recipe, an average, or a model"). | **No.** `StockLine` holds theoretical and counted as the same type. |
| ESTIMATED | `EvidenceType::ESTIMATED` → ESTIMATED | Yes |
| AI SUGGESTED | `EvidenceType::AI_SUGGESTED` → certainty ESTIMATED, provenance AI_SUGGESTION, `isBinding()` false; cannot allocate (R04), cannot be accepted on its own authority | Badge collapses to ESTIMATED; provenance keeps it distinct |
| RECONCILED | A **status**, not an evidence class: `PlatformSettlement::RECONCILED`, `CashLedger` `'RECONCILED'`, `StockLine::MATCHED` / `WITHIN_TOLERANCE`. It is the outcome of comparing two evidences and is correctly not a certainty. | Yes, as a status chip |

**The sentence the audit turns on:** *"expected meat consumption = 540 kg must
never look as authoritative as bank received 5 000 PLN."* For money this holds
by construction: a 5 000 zł `Figure` with `BANK_CONFIRMED` and a 540 zł
`Figure` with `ESTIMATED` cannot be summed without the split showing. **For
quantities it does not hold**: 540 kg theoretical and 18 kg counted are both
`Quantity`, serialise with the same three keys, and nothing in `StockLine`
labels one as a model and the other as a measurement. The doc comment on
`Recipe` says "everything derived from it is ESTIMATED, and the code says so by
construction" — the code does not say so; the comment does. Recorded in
`OPEN_ITEMS.md` as a P1 blocker for Page 2, with the design: a
`MeasuredQuantity` (quantity + evidence type) and two new evidence cases,
`STOCK_COUNTED` (ACTUAL, names its counter, not corroborated — the twin of
`CASH_COUNTED`) and `RECIPE_THEORETICAL` (ESTIMATED).

Two further observations on the vocabulary:

- `Total::of([])` reports `certainty` = ESTIMATED for NO DATA. `describe()`
  prints "NO DATA", but `jsonSerialize()['certainty']` says `ESTIMATED`, so a
  screen built from the JSON would badge an empty period as an estimate.
  Absence is not an estimate any more than it is zero; NO DATA deserves its own
  state rather than borrowing one of the four.
- `CASH_COUNTED` exists as an evidence type but `CashLedger::$physicalCount` is
  bare `Money`: the ledger's own count is never a `Figure`, so the evidence type
  is only ever applied by callers. The count that R26 insists must name its
  counter carries no evidence label of its own.

---

## 5. Where §5 of the briefing is right, and where it is not

The briefing asked which of its "most likely wrong" items this audit disagrees
with.

**Every threshold is invented — agreed, and worse than stated.**

| Threshold | Lives in | Default | Parameter? | Disclosed in §5? |
|---|---|---|---|---|
| Target / warning margin | `PriceReview` | 65 / 55 | yes | 65 yes; `.env.example` said **60 / 50** — code and example disagreed. Aligned to the code; both marked UNVALIDATED. |
| Stock tolerance | `StockLine` | 2 % | yes | yes |
| Revenue drop | `ShopMonitor` | −15 % | yes, but `MonthlyManagementReport` built `new ShopMonitor()` internally, so **no configured value could reach the report**. Fixed: the report takes a monitor (R34). | yes |
| Cost rise | `ShopMonitor` | 20 % | yes (same fix) | yes |
| Margin drop | `ShopMonitor` | 5 points | yes | yes; absent from `.env.example` — added |
| Platform review threshold | `PlatformSettlement::status()` | 1 % | **was hard-coded**; now a constructor parameter with the default named `DEFAULT_REVIEW_THRESHOLD_PERCENT` and marked UNVALIDATED | yes |
| Baseline minimum | `Baseline::MINIMUM_PERIODS` | 3 | constant | yes |
| Food-cost gap alert | `StockReconciliation::reviewFlags()` | 5 % | **hard-coded** | **no — undisclosed** |
| Accounts comparison tolerance | `AccountsComparison` | 2 % | yes | **no — undisclosed** |
| Cash tolerance | `CashLedger` | 0 | yes | in `.env.example` |
| Platform tolerance | `PlatformSettlement` | 0 | yes | absent from `.env.example` — added |

Nothing reads `.env`. The keys now mirror the constructor arguments one to one
so they cannot drift again, and the file says so. None of these is a business
truth and no test added here pins a value: R31 and R34 pin that the threshold is
a *parameter*, which is the property that keeps it honest.

**The accusation word list is a safety net — agreed, and it was already
leaking by layout.** `CashLedger::reviewFlags()` wrote "160,00 zł less … counted
by Anna" — a shortfall with a name beside it, in a REQUIRES_REVIEW flag, with no
forbidden word anywhere. The name is required on the record (R26) and is now
kept off the sentence (R35). The keyword list is not the mechanism; the
mechanism is that a `ReviewFlag` has no field for a person and never receives
one. The list should still grow (`zabrał`, `przywłaszczył`, `pobrał`, "took",
"pocketed" is there, "walked off with" is not), but no list catches "the
difference started after the shift change", and the field-level rule is what
prevents that sentence from being built.

**`CASH_COUNTED` as ACTUAL — agreed with the classification, disagree with the
asymmetry.** Counted cash is real; the code already excludes it from
`corroborated()`. The inconsistency is elsewhere: the cash count names its
counter and the stock count does not, and neither count is a `Figure`. Fix once,
in `EvidenceType`, by giving stock its `STOCK_COUNTED` twin (§4 above).

**The median baseline — agreed, with a stronger version.** Same-month-last-year
is one data point. Under the Evidence Law that is n = 1, and the honest output
for a seasonal shop with under two years of history is **CANNOT SEPARATE
seasonal from decline**, shown as such, rather than a like-for-like comparison
that is really a single number. Also: `ShopMonitor` prints "the median of the
last N periods" while `Baseline` accepts any keyed array — the periods need not
be consecutive, so the label can describe a comparison that was not made.

**Contribution is not profit — agreed.** The renderer's channel header says
"before rent, wages and utilities". No change.

**The demo figures — agreed.** Nothing in this audit quotes them as findings.

**One item §5 does not list and should:** the briefing says the thresholds "are
defaults in `.env.example`". They were not — the example disagreed with the
code and nothing read it — and the example is now a mirror of the code rather
than a configuration. A configuration layer is P0 work and does not exist.

---

## 6. Official Accounts remain untouched

Measured, not asserted:

- `./bin/check-shop-isolation.sh`: 10 checks, 0 violations, now including
  `deploy/` (the vhost and the server script are where a shared socket or a
  shared cookie domain would first appear).
- `../bin/check-isolation.sh`: 4 checks pass over `shop-intelligence/` as well.
- `IsolationTest`: 7 tests — no `Poland\` import, no KSeF or filing path, no
  trading reference, no database connection, no outbound call, `AccountsSnapshot`
  the only route in and free of any fetch/pull/load/connect/query.
- `test_the_comparison_with_the_official_accounts_reports_and_never_corrects`:
  no write-shaped method on `AccountsComparison`.
- The new deployment files name `shop_intelligence`, a `shop` system user,
  `/srv/shop-intelligence`, `/var/lib/shop-intelligence`,
  `php8.5-fpm-shop.sock` and `shop_intelligence_session`; the server script
  refuses to run if any of those names contains "accounting" or "liberu", and
  proves after creating the database user that it can see no other schema.

What is shared: the repository, and on the server the operating system, the
MariaDB **process** (not the schema, not the user) and nginx (not the server
block, not the pool). Nothing else.

---

## 7. Defects fixed in this audit (small, verified, each with a test)

| Defect | Where | Fix | Test |
|---|---|---|---|
| Partial payment had no test | `AllocationSet` | — | R28, R29 |
| Immutability of posted revisions was claimed, not tested | `MatchHistory`, `MatchRevision`, `AllocationSet` | — | R30 |
| `UNRECONCILED` branch untested; 1 % threshold hard-coded | `PlatformSettlement::status()` | threshold is a named, documented constructor parameter | R31 |
| One result with a footnote instead of two results | `ProfitStatement`, `TextReportRenderer` | `confirmedResult()`, `projectedResult()`, `unconfirmedPortion()`; renderer prints both | R32, R32b, renderer test |
| `RecipeBook::add()` silently overwrote an existing recipe | `RecipeBook` | `add()` refuses; `revise()` keeps the superseded version and requires a version | R33 ×3 |
| Report ignored configured thresholds | `MonthlyManagementReport` | takes an optional `ShopMonitor` | R34 |
| Counter's name printed beside the shortfall | `CashLedger::reviewFlags()` | name stays on the record, off the sentence | R35 |
| Doc comment claimed "no method returning the bare Money" while `managementProfitAmount()` exists | `ProfitStatement` | comment now tells the truth and states the rule for renderers | — |
| `.env.example` thresholds disagreed with code; two thresholds undisclosed | `.env.example` | mirrors every constructor default, marked UNVALIDATED, notes that nothing reads it | — |
| Shop Intelligence absent from CI; `ISOLATION.md` said otherwise | `.github/workflows/ci.yml` | job added | CI |
| Isolation guard did not scan deployment files | `bin/check-shop-isolation.sh` | scans `deploy/` | guard |

Not fixed here, recorded in `docs/OPEN_ITEMS.md` with the design for each:
quantity certainty (P1, before Page 2), two dates on every row (P1, before
Page 1), mandatory source reference at the import boundary (P2, with the
importers), correction chains for counts, cost entries, statements and
snapshots (P0, with the month close), NO DATA as its own state, `confirmedBy()`
at a different amount, stock count naming its counter, the configuration layer.

---

## 8. Final real-data gate

**REQUIRES HUMAN.** Nothing above is evidence about the shop. 72 passing tests
prove that the code refuses the mistakes the specification names; they prove
nothing about whether the recipes match the kitchen, whether the thresholds fit
the history, or whether the accountant's figures and this application's figures
differ for the reasons `AccountsComparison` lists. The pilot is one real month:
the real bank statement, the real Z-reports, the real Glovo and Uber Eats
statements, one real count with a named counter, compared with the accountant's
closed figures for the same month, with every difference explained or marked
CANNOT SEPARATE. It cannot start until the P0 items exist, because there is
nowhere to put the data.
