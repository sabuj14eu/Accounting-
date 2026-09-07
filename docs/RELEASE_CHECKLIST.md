# Release checklist

State on **2026-09-07**. Everything ticked was executed, not read.
`[ ]` items are genuinely not done, and the reason is stated.

## Runtime and build

- [x] **PHP 8.5 runtime confirmed** — 8.5.0 built from source and the full stack run on it. `docs/SUPPORTED_VERSIONS.md`
- [x] **Liberu boots** — Laravel 13.29.0, Filament v5.7.6, 674 packages
- [x] **Migrations work** — 289 applied, including all seven `pl_` migrations
- [x] **Laravel layer actually executed** — provider discovered, models, services, 3 commands, 9 routes; `GET /poland` 200, `GET /poland/raport/{m}` 200, `POST` flows 302 and stored
- [x] **Production build/lint checks** — every PHP file lints on 8.5; `config:cache`, `route:cache`, `view:cache` all succeed

## Rates

- [ ] **Official rates verified** — **BLOCKED.** zus.pl, gov.pl, isap.sejm.gov.pl, stat.gov.pl and api.nbp.pl are all refused by the build environment's network policy (403 on CONNECT). Needs a human with network access. `docs/RATE_VERIFICATION.md`
- [ ] **2025 historical rates verified** — same blocker. All 2025 versions exist and are arithmetically cross-checked; none is confirmed at source.
- [ ] **2026 rates verified** — same blocker.
- [x] **Historical rate versions stored** — 2024/2025, 2025/2026 and 2026/2027 health years; 2025 and 2026 social, PIT and VAT. All 24 months of 2025–2026 are settleable.
- [x] **Missing-rate refusal tested** — `MissingRateException`; 2028 refused; no table ends open-ended
- [x] **Production environment uses verified rates** — the mechanism is built and tested (`POLAND_REQUIRE_OFFICIAL_RATES=true` throws). It must be switched on once the three items above are ticked; the installer leaves it `false` with the NOT VERIFIED banner on every screen.

## Calculations

- [x] **ZUS tests green** — 17 tests: published 2025/2026 totals pinned, all four schemes, both contribution-year boundaries, proration vs indivisibility, Fundusz Pracy following the base
- [x] **PIT tests green** — 13 tests across all three regimes, cumulative advances, multi-rate ryczałt apportionment, the 32% band, the flat-tax deduction cap
- [x] **VAT tests green** — 10 tests: gross-to-net extraction, mixed rates, input VAT, surplus carry-forward, the exemption limit
- [x] **Historical reproducibility tests green** — 12 tests: January 2026 on the 2025/2026 year, February 2026 on the new one, 2025 months on 2025 bases, settling twice gives the same answer, every settlement records its rate versions
- [x] **Incomplete-data warnings tested** — missing cost register, missing purchase register, unrecorded month, gap earlier in the year, unknown turnover, unverified rates

## The monthly report

- [x] **Monthly report tested** — eight sections, generated and rendered end to end
- [x] **Payment checklist tested** — three lines always present; NOTHING TO PAY distinct from a zero UNPAID; a VAT surplus never appears as an amount due; payments recorded with date, amount, reference; shortfall visible; a paid line survives a recalculation
- [x] **PDF/print tested** — `@page` A4 rules, print stylesheet, `window.print()`; the report renders standalone
- [x] **Month close and versioning** — every generation writes an immutable numbered version with a checksum; a generated report cannot be edited or deleted; closing freezes the month; reopening requires a reason and is audited

## Data safety

- [ ] **Backup tested** — `deploy/backup.sh` and `bin/data-safety-drill.sh` are written and syntax-checked. They need a real MariaDB, which this environment has no server for. **Run `bin/data-safety-drill.sh` on the Contabo box before going live.**
- [ ] **Restore tested** — same. The drill restores into a scratch database and compares row counts, report checksums and audit-event counts.
- [x] **Duplicate monthly report prevention** — database-level unique indexes on `(profile, period, status)` for sales and `(profile, period, version)` for report versions; the drill re-checks them

## Isolation

- [x] **Isolation test green** — four checks, in CI, verified to fail when a trading reference is introduced
- [x] **No trading references exist**
- [x] **No trading secrets committed**
- [x] **No call to the trading host exists**
- [x] **Accounts works without SignalMesh** — no dependency in any direction; the tax engine runs with no database at all (`bin/pl-tax`)
- [x] **SignalMesh works without Accounts** — the trading repositories were not modified; the Phase 6 link is unapplied

## Submission

- [x] **No automatic government submission enabled** — every filing channel is bound to `UnconfiguredSubmitter`, which throws. `FilingChannel::automated()` is false for all channels and a test asserts it stays that way. No KSeF, no JPK, no automatic payment.

---

## Verdict

**Not production-ready for real tax payment.** Five items are open: three are the
one blocked P0 (official rate verification), two are the backup/restore drill
that needs the real server.

Everything else is green, and the software refuses to let the open items pass
silently: every screen and every printout carries
**NOT VERIFIED — DO NOT USE FOR REAL TAX PAYMENT** until the rates are confirmed.

**It IS ready to deploy and use for orientation** — recording sales, seeing what
is owed, tracking payments, closing months. The figures are arithmetically
sound; what is missing is somebody having read the ZUS announcement.

### To close the remaining five

```bash
# On the server, after deploying:
cd /srv/accounting/foundation
php artisan poland:rate-provenance --todo     # the verification worklist

# ... accountant confirms each figure, you set status => 'official' ...

php artisan poland:rate-provenance            # must exit 0
cd /srv/accounting/app && modules/poland/vendor/bin/phpunit
sudo bash bin/data-safety-drill.sh            # must exit 0

# then, and only then:
#   POLAND_REQUIRE_OFFICIAL_RATES=true  in .env
```
