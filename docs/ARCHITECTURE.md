# Architecture

```
                 ┌──────────────────────────┐
                 │  SignalMesh (trading)    │
                 │  app.signalmesh.dev      │
                 └────────────┬─────────────┘
                              │  one hyperlink, Phase 6
                              ▼
    ┌───────────────────────────────────────────────────┐
    │  Accounting — account.signalmesh.dev              │
    │                                                   │
    │  ┌─────────────────────────────────────────────┐  │
    │  │  Liberu accounting ERP (installed, pinned)  │  │
    │  │  ledger · invoicing · banking · reporting   │  │
    │  └────────────────────┬────────────────────────┘  │
    │                       │  Composer path repository │
    │  ┌────────────────────▼────────────────────────┐  │
    │  │  modules/poland                             │  │
    │  │                                             │  │
    │  │   Laravel layer   provider · models · HTTP  │  │
    │  │   ─────────────────────────────────────────  │ │
    │  │   Reporting       SettlementEngine          │  │
    │  │   Calculators     VAT · ZUS · PIT           │  │
    │  │   Rates           effective-dated tables    │  │
    │  │   Domain          Money · Period · Ledger   │  │
    │  └─────────────────────────────────────────────┘  │
    │                                                   │
    │  own DB · own queue · own storage · own login     │
    └───────────────────────────────────────────────────┘
```

## Why the foundation is installed rather than vendored

Upstream is 7 141 files and 45 MB across 466 modules. Forking it into this
repository would make every upstream upgrade a merge of that size and would bury
the Poland layer — the part that is actually ours — in noise.

So `bin/install-foundation.sh` pins upstream by commit and installs it, and the
Poland layer is mounted as a Composer path repository, which is exactly how
upstream composes its own 466 modules. Upgrading upstream is a one-line change
to `FOUNDATION_REF` plus a test run.

The trade-off is that this repository is not self-contained: a deploy needs
network access to GitHub and Packagist. That is already true of any Composer
project. If reproducibility without network access is ever required, the answer
is a Composer mirror, not a fork.

## The layering rule

Dependencies point one way only:

```
Laravel layer  →  Reporting  →  Calculators  →  Rates  →  Domain
```

`Domain`, `Rates`, `Calculators` and `Reporting` contain no Laravel. That is not
architectural taste: it is what makes `bin/pl-tax` work with nothing but PHP, so
a taxpayer can get a number when the application, the database or the queue is
down. `bin/check-isolation.sh` fails the build if a Laravel import appears in
those directories.

## Why the engine replays the year

Almost nothing about a Polish settlement month is local to that month:

- the PIT advance is cumulative from 1 January;
- the ryczałt health band is chosen by revenue accumulated since 1 January;
- the health contribution under the scale and the flat tax is based on the
  previous month's income;
- deductions depend on contributions from earlier months.

`SettlementEngine::replayYear` therefore walks January forward and hands each
month what the months before it produced. Settling a month in isolation would
drift, and the drift would be invisible.

## Data model

| Table | Holds |
|---|---|
| `pl_tax_profiles` | regime, ryczałt rate, VAT status, ZUS scheme, start date, elections |
| `pl_sales_reports` + `_lines` | cash-register reports as entered; corrections supersede, never overwrite |
| `pl_purchase_summaries` | deductible costs and input VAT per month |
| `pl_settlements` | computed settlements, the full report JSON, and the rate stamps used |
| `pl_audit_events` | append-only; updates and deletes refused at the model level |

Everything the module owns is prefixed `pl_`, so upstream tables and ours can
never collide and an upgrade never has to reconcile them.

`pl_settlements.report` stores the report verbatim, including which rate-table
versions produced it. Rates change; a settlement must stay reproducible as it
stood on the day it was made, and recomputing it later from current tables would
quietly rewrite history.

`filed_at` is the only field in the system that means "submitted". No
calculation sets it.
