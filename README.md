# SignalMesh Accounts — Polish JDG accounting

A **separate** accounting application for a Polish sole trader (JDG), built on
the [Liberu accounting ERP](https://github.com/liberusoftware/accounting-erp-laravel)
and extended with a Poland compliance layer: VAT, ZUS, PIT, JPK and KSeF.

It shares nothing with the SignalMesh trading platform. Not a database, not a
queue, not a session, not a credential. The only link between them is a
navigation item.

---

## What works today

The workflow this was built for:

> *"I only give my sales report amount — the kasa fiskalna amount. Then give me
> a report of how much I need to pay: ZUS, VAT, PIT."*

```bash
cd modules/poland
./bin/pl-tax --profile=examples/profile.json --sales=2026-08:22150
```

```
==============================================================================
                    ROZLICZENIE MIESIĘCZNE — SIERPIEŃ 2026
------------------------------------------------------------------------------
 DO ZAPŁATY
------------------------------------------------------------------------------
   ZUS (społeczne + zdrowotna)                                     2 757,34 zł
      termin: 21.09.2026 (przesunięty z 20.09.2026)
   VAT (podatnik zwolniony)                                            0,00 zł
   Ryczałt (PIT)                                                     594,00 zł
      termin: 21.09.2026 (przesunięty z 20.09.2026)
------------------------------------------------------------------------------
   RAZEM                                                           3 351,34 zł
 Zostaje ze sprzedaży             18 798,66 zł  (obciążenia = 15,1% sprzedaży)
```

The tax engine needs nothing but PHP 8.2+. No database, no framework, no queue
— so an answer is available even when the application is down.

Inside the ERP the same engine backs a web dashboard
(`/poland`) and `php artisan poland:report 2026-08 --sales=22150`.

## Repository layout

```
modules/poland/          The Poland tax and compliance layer (a Composer package)
  config/rates/          Versioned, effective-dated rate tables with sources
  src/Domain/            Money, Period, TaxProfile, Ledger — framework-free
  src/Rates/             Effective-dated lookup; refuses to extrapolate
  src/Calculators/       VAT, ZUS, PIT
  src/Reporting/         Settlement engine, monthly report, renderers
  src/Laravel/           Service provider, models, controller, artisan commands
  bin/pl-tax             Standalone CLI
  tests/                 85 tests
bin/install-foundation.sh  Installs the Liberu ERP and mounts this module
bin/check-isolation.sh     Fails if a trading dependency appears
deploy/                    nginx, systemd units, verified backup script
docs/                      Architecture, isolation, deployment, roadmap
```

The Liberu ERP is **installed, not vendored**. Upstream is pinned by commit in
`bin/install-foundation.sh`, so upgrading it is a one-line change rather than a
7 000-file merge, and the double-entry ledger, invoicing, banking and reporting
come from upstream rather than being rebuilt here.

## Getting started

```bash
# Tax engine only — works anywhere with PHP 8.2+
cd modules/poland && composer install && vendor/bin/phpunit

# Full application — requires PHP 8.5 (see docs/DEPLOYMENT.md)
bin/install-foundation.sh
cd foundation && php artisan migrate && php artisan poland:verify-rates
```

## What this software does not do

- **It does not file anything.** Every figure is a calculation. Nothing is sent
  to ZUS, to the tax office or to KSeF, and a settlement is marked as filed only
  when a real submission returns a reference.
- **It does not replace an accountant.** It shows the arithmetic, the rates it
  used and where those rates came from, so both of you can check it.
- **It does not touch trading.** See `docs/ISOLATION.md`.

## Status

Phase 1 (foundation, Polish tax engine, kasa fiskalna workflow) is built and
tested. Phases 2–6 — NBP rates, KSeF, JPK, automation, the SignalMesh
navigation item — are specified in `docs/ROADMAP.md` and not yet built.
`docs/OPEN_ITEMS.md` is the honest list of what is deferred.
