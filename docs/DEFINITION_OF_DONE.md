# Definition of Done

The seventeen conditions, each with its current state and where the proof is.
Nothing is marked done on the strength of code existing — only on something
having been run.

Legend: **DONE** (verified) · **PARTIAL** (works, with a stated gap) ·
**NOT DONE** · **BLOCKED** (cannot be done here, and why).

| # | Condition | State | Proof / gap |
|---|---|---|---|
| 1 | PHP 8.5 runtime confirmed | **DONE** | Built `php-8.5.0` from source and ran the whole stack. `docs/SUPPORTED_VERSIONS.md` |
| 2 | Liberu boots | **DONE** | `artisan --version` → Laravel 13.29.0 on the installed ERP |
| 3 | Migrations work | **DONE** | 288 migrations applied, incl. all 5 `pl_` migrations |
| 4 | Laravel layer actually executed | **DONE** | Provider discovered; models, recorder, audit trail, 3 commands, `GET /poland` 200, `POST /poland/sprzedaz` 302 → stored → rendered |
| 5 | Tax engine tests pass | **DONE** | 106 tests, 268 assertions, green on PHP 8.2–8.5 |
| 6 | Official Polish rate sources verified | **BLOCKED** | zus.pl, gov.pl, isap.sejm.gov.pl, stat.gov.pl and api.nbp.pl are all refused by this environment's network policy (403 on CONNECT). The machinery is built and the checklist is generated — a human with network access must do the reading. `docs/RATE_VERIFICATION.md` |
| 7 | Historical rate versions stored | **DONE** | Effective-dated versions for 2025 and 2026; every settlement stores the version identifiers and full provenance it used, so it stays reproducible when rates change |
| 8 | Missing-rate refusal works | **DONE** | `MissingRateException`; `test_it_refuses_to_extrapolate_into_an_unconfigured_year`, `test_a_month_with_no_rate_version_is_refused` |
| 9 | ZUS edge cases pass | **DONE** | 17 ZUS tests incl. the Feb–Jan contribution year, January-on-previous-year, proration vs indivisibility, Fundusz Pracy following the base |
| 10 | VAT limitations correctly displayed | **DONE** | Output VAT and VAT payable are separate fields; without a purchase register the result is labelled an upper bound and flagged as an estimate |
| 11 | Skala/liniowy incomplete-cost limitation displayed | **DONE** | `isEstimate`, a named warning, and a blocker to filing; `test_income_regimes_flag_a_missing_cost_register_as_an_upper_bound` |
| 12 | KSeF/JPK/PIT/ZUS architecture versioned | **PARTIAL** | Rules and rates are effective-dated and recorded per calculation; `SchemaRegistry` selects schema versions by period and refuses unregistered ones. No KSeF or JPK schema is registered yet — that is Phase 3/4 work, not a gap in the mechanism |
| 13 | Isolation test passes | **DONE** | `bin/check-isolation.sh`, four checks, tested in both directions, runs in CI |
| 14 | No trading references exist | **DONE** | Isolation check 1 |
| 15 | No trading secrets committed | **DONE** | Isolation check 3; `.env.example` holds no trading variable |
| 16 | No call to the trading host exists | **DONE** | Isolation check 2 |
| 17 | Accounts runs if SignalMesh is offline | **DONE** | No dependency of any kind exists in either direction. The tax engine runs with no database, no queue and no ERP at all (`bin/pl-tax`) |
| 18 | SignalMesh runs if Accounts is offline | **DONE** | The trading repositories were not modified. The Phase 6 change is a plain external link, unapplied — `docs/SIGNALMESH_NAV_LINK.md` |

## The one blocker

**Condition 6.** Every official Polish source is refused by this environment's
egress policy:

```
www.zus.pl:443         gateway answered 403 to CONNECT
www.gov.pl:443         gateway answered 403 to CONNECT
isap.sejm.gov.pl:443   gateway answered 403 to CONNECT
stat.gov.pl:443        gateway answered 403 to CONNECT
api.nbp.pl:443         gateway answered 403 to CONNECT
```

So the reading cannot be done from here, by anyone, at any point in this
session. What was done instead:

- every rate carries its effective period, value, meaning, version, source
  document, the URL it actually came from, **the official URL where it must be
  confirmed**, the check date, the checker and notes;
- every figure is cross-checked arithmetically against its own stated formula,
  which catches transcription errors but cannot catch a wrong source;
- `poland:rate-provenance --todo` prints the verification worklist;
- production refuses to settle on unverified rates, and preparation refuses to
  build a document from them.

This is the difference between "we'll check the rates later" and a system that
will not produce a fileable number until somebody has.

## Not production-ready until

Condition 6 is closed, and then re-verify 5, 9, 10 and 11 — verification may
change figures, and the tests pin figures.
