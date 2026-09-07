# Supported versions

Everything here was **executed**, not read off a `composer.json`. The date is
the date it ran.

## Verified combination — 2026-09-07

| Component | Version | How it was verified |
|---|---|---|
| PHP | **8.5.0** | Built from `php-src` tag `php-8.5.0`; ran the whole stack |
| Laravel | **13.29.0** | `php artisan --version` on the booted application |
| Filament | v5.7.6 | resolved in `composer.lock` |
| Livewire | v4.4.3 | resolved in `composer.lock` |
| Liberu accounting ERP | `3a23437a432c74637aca56bb0daed27430d481ee` | pinned in `bin/install-foundation.sh` |
| Composer packages | 674 | `composer install --no-dev` |
| Migrations applied | 288 (incl. this module's 5) | `php artisan migrate --force` |
| Poland module | PHP ≥ 8.2 | test suite green on 8.2–8.5 |

What was actually executed on that stack:

- the ERP boots (`artisan --version`, `artisan list`, `artisan route:list`);
- the module's service provider is discovered (`bootstrap/cache/packages.php`);
- all 288 migrations run, including `pl_tax_profiles`, `pl_sales_reports`,
  `pl_sales_report_lines`, `pl_purchase_summaries`, `pl_settlements`,
  `pl_audit_events`, `pl_prepared_documents`;
- Eloquent models create, read and convert to domain objects;
- a month is recorded, settled and stored;
- a duplicated month is refused; a correction supersedes without overwriting;
- the audit trail refuses updates and deletes at the model level;
- filing without a reference is refused; filing with one is recorded;
- `poland:report`, `poland:verify-rates` and `poland:rate-provenance` all run;
- `GET /poland` returns 200 and renders every element of the report;
- `POST /poland/sprzedaz` returns 302, parses `31 250,50` correctly, stores it,
  and the following GET shows it.

## PHP 8.5 is a hard requirement for the ERP

Not a soft `composer.json` constraint. Upstream uses `#[\Override]` on class
properties, which is PHP 8.5 syntax, so on 8.4 the application fails to
**parse** — before any autoloader runs — and the error names an attribute rather
than a version:

```
PHP Fatal error: Attribute "Override" cannot target property
  (allowed targets: method) in app/Providers/AuthServiceProvider.php on line 18
```

`composer install --ignore-platform-reqs --no-dev` succeeds on 8.4; only running
it fails. `bin/install-foundation.sh` checks `PHP_VERSION_ID >= 80500` up front
so this surfaces as a clear message instead of that one.

## The tax engine has a lower floor on purpose

`modules/poland` requires only **PHP 8.2** and no framework. Its test suite runs
on 8.2, 8.3, 8.4 and 8.5 in CI, and `bin/pl-tax` produces the full monthly
report with no database, no queue and no ERP.

That is not an accident of packaging. It means a taxpayer can still get their
ZUS, VAT and PIT figures when the application is down, the host has not been
upgraded to 8.5 yet, or the database is unavailable.

## Extensions required

**ERP host:** `mbstring`, `intl`, `dom`, `xml`, `xsl` (for JPK/KSeF schema
validation), `pdo_mysql`, `redis`, `zip`, `gd`, `bcmath`, `curl`, `openssl`.

**Tax engine alone:** `mbstring` and the always-on core only.

## Upgrading the foundation

Change `FOUNDATION_REF` in `bin/install-foundation.sh`, run
`bin/install-foundation.sh` into a scratch directory, migrate, and run the
integration checks in `.github/workflows/ci.yml` (`laravel-integration`). Update
the table above with what actually ran, and the date.

Never update this table from a changelog. The whole point of it is that
somebody ran the thing.
