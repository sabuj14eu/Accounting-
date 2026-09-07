# Open items

Deferred work for the accounting application. An item deferred in conversation
is an item forgotten — if it is not here, it does not exist. Delete an entry
only when it is done and verified, and say where the proof is.

---

## P0 — must be settled before real accounting use

### The rate tables have been read from secondary sources, not from the statutes
Every figure in `modules/poland/config/rates/` was taken from Polish accounting
press and cross-checked arithmetically against its own stated formula (the tests
in `RateTableTest` verify, for example, that each ryczałt health band really is
9% of its stated percentage of the reference wage, and the published headline
ZUS totals for 2025 and 2026 are pinned in `ZusCalculatorTest`). That is a
strong consistency check and it is **not** the same as reading the statute or
the ZUS/MF announcement.

Before the first real filing, an accountant should confirm against primary
sources: the 2026 social bases (5 652,00 / 1 441,80 zł), the health contribution
amounts for both contribution years, the flat-tax health deduction cap
(14 100 zł for 2026), and the VAT exemption limit of 240 000 zł.
**Owner: the taxpayer's accountant. Proof required: a signed-off note here.**

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

## P1 — needed for a complete Phase 1

### Polish chart of accounts and company defaults not configured
The Liberu foundation ships its own chart of accounts. Polish account
numbering, VAT registers and document types have not been configured, and the
foundation has not been booted on a PHP 8.5 host in this project yet.
**Next step: run `bin/install-foundation.sh` on a PHP 8.5 host, then configure.**

### The application layer has not been executed
`src/Laravel/*`, the migrations and the dashboard view are written and
syntax-checked but have never run, because the ERP needs PHP 8.5 and the
development environment had 8.4. The tax engine underneath them is fully tested
and does not depend on them.
**Proof required: `php artisan migrate` and one settlement recorded through the
web form on a PHP 8.5 host.**

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

## Deliberately not done

### The SignalMesh navigation link (Phase 6)
Not applied. The instruction was to start with Phase 1, and the trading platform
is not modified without a reason and a deliberate decision.
`docs/SIGNALMESH_NAV_LINK.md` has the exact change.

### Single sign-on with the trading platform
Phase 1 has its own login by design. SSO would be a deliberate OAuth/OIDC
integration between two applications that stay separate — never a shared session
store, which would turn an outage of either into an outage of both.
