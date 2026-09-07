# The 27 regression tests

§29 of the specification asks for a named regression suite. These are the 27,
each one pinning a rule that the specification states in prose, mapped to the
test that would fail if somebody removed it.

**A caveat Fable should settle first.** These 27 were derived from the rules in
the specification body — §3 through §28 — rather than transcribed from the §29
list itself. Before signing them off, read §29 line by line against this table
and tell me which of its items are missing. A test I invented that happens to
overlap is not the same thing as the test that was asked for.

Run them:

```bash
cd shop-intelligence
../modules/poland/vendor/bin/phpunit -c phpunit.xml     # or: vendor/bin/phpunit
./bin/check-shop-isolation.sh
php bin/shop-demo
```

| # | The rule | Test |
|---|---|---|
| R01 | Declared cash is never presented as bank-confirmed | `test_r01_r02_a_fully_allocated_invoice_keeps_bank_and_declared_apart` |
| R02 | 5 000 settled 3 000 bank + 2 000 declared is FULLY ALLOCATED, split preserved | same test |
| R03 | The same payment imported twice makes a document OVER_ALLOCATED, not double-settled | `test_r03_over_allocation_is_detected_and_explained` |
| R04 | §22 — an AI suggestion cannot settle a document | `test_r04_an_ai_suggestion_cannot_settle_a_document` |
| R05 | §6 — correcting a match keeps the original revision, with who and when | `test_r05_correcting_a_match_keeps_the_original` |
| R06 | A correction must say why the earlier match was wrong | `test_r06_a_correction_must_state_why_the_earlier_match_was_wrong` |
| R07 | A platform payout shortfall is flagged, never attributed | `test_r07_a_platform_payout_shortfall_is_flagged_without_accusation` |
| R08 | A payout nobody has seen is NO_PAYOUT_RECORDED, never RECONCILED | `test_r08_a_missing_platform_payout_is_never_reconciled` |
| R09 | Provenance combines by worst case, never by average | `test_r09_provenance_combines_by_worst_case` |
| R10 | Certainty combines by worst case | `test_r10_certainty_combines_by_worst_case` |
| R11 | §25 — a mixed total cannot be rendered without its split | `test_r11_a_mixed_total_cannot_be_described_without_its_split` |
| R12 | An empty set of figures is NO DATA, not ACTUAL zero | `test_r12_an_empty_set_of_figures_is_not_actual_zero` |
| R13 | A review flag cannot accuse anyone (English **and** Polish) | `test_r13_a_review_flag_cannot_accuse_anyone`, `test_r13_the_accusation_guard_reads_polish` |
| R14 | A review flag must offer innocent explanations to exist at all | `test_r14_a_review_flag_must_offer_innocent_explanations` |
| R15 | §8 — a stock difference is REQUIRES REVIEW with its innocent causes listed | `test_r15_a_stock_difference_requires_review_and_lists_innocent_causes` |
| R16 | A product with no recipe consumes an UNKNOWN amount, never zero | `test_r16_sales_without_a_recipe_are_reported_not_silently_ignored` |
| R17 | An uncounted ingredient is NOT_COUNTED, never a match | `test_r17_an_uncounted_ingredient_is_not_counted_not_matched` |
| R18 | Grams are not millilitres and the type refuses to add them | `test_r18_quantities_of_different_units_cannot_be_combined` |
| R19 | §16 — a recurring cost is EXPECTED until something confirms it | `test_r19_a_recurring_cost_is_expected_until_confirmed` |
| R20 | A person typing "paid" does not turn EXPECTED into ACTUAL | `test_r20_a_recurring_cost_cannot_be_confirmed_by_a_person_typing_paid` |
| R21 | §11 — management profit is never presented as tax profit | `test_r21_management_profit_is_never_presented_as_tax_profit` |
| R22 | Management profit inherits the worst certainty of its inputs | `test_r22_management_profit_inherits_the_worst_certainty_of_its_inputs` |
| R23 | §17 — the price review explains and never changes a price | `test_r23_the_price_review_never_changes_a_price` |
| R24 | A platform commission can turn a healthy product red | `test_r24_a_platform_commission_can_turn_a_healthy_product_red` |
| R25 | §18 — a cash difference is a question, never an accusation | `test_r25_a_cash_difference_requires_review_without_naming_a_cause` |
| R26 | A cash count must name who counted it | `test_r26_a_cash_count_must_name_who_counted_it` |
| R27 | §22 — every forbidden AI action throws, all eleven | `test_r27_every_forbidden_ai_action_throws` |

## Unnumbered tests that matter as much

- `IsolationTest` — six checks over every source file: no accounting-engine
  import, no KSeF or filing path, no trading reference, no database connection,
  no outbound call, and `AccountsSnapshot` as the only route in from the
  accounting side.
- `test_the_comparison_with_the_official_accounts_reports_and_never_corrects` —
  asserts the **absence** of any write-shaped method on `AccountsComparison`.
- `test_notes_about_how_to_read_a_figure_are_not_ranked_as_losses` — a 9 000 zł
  note must not head a worklist above a 500 zł real problem.
- `test_the_monitor_stays_silent_without_enough_history` — three data points are
  not a trend.
- `test_the_cash_ledger_shows_a_running_balance_after_every_movement` — the
  banking-app shape, tested.

## Why R27 is written as a loop

`test_r27_every_forbidden_ai_action_throws` iterates over
`AiBoundary::forbiddenActions()` rather than listing eleven cases. Adding a
twelfth prohibition to the enum therefore adds a twelfth assertion
automatically: **a new prohibition cannot be declared without being enforced.**
The companion test then checks the enum against the specification's own list by
name, so a prohibition cannot be quietly deleted either.
