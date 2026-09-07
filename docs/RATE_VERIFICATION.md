# Verifying the rates against official sources

This is the P0. Until it is done, the software will not let a figure be
presented as fit for filing — not as a matter of policy, but mechanically.

## Where things stand

Every rate version in `modules/poland/config/rates/` is currently marked
`secondary`. That means:

- the numbers were taken from competent Polish accounting publications;
- each was **cross-checked arithmetically against its own stated formula** — the
  tests assert that the full ZUS base really is 60% of the stated forecast wage,
  that each ryczałt health band really is 9% of its stated percentage of the
  reference wage, and that the published headline totals (1 926,76 / 1 773,96 /
  456,18 / 442,90 / 420,86 zł) reproduce exactly from those bases and rates;
- **nobody has read the issuing authority's own publication.**

The arithmetic check is worth having and is not the same thing. A figure that is
internally consistent can still be the wrong figure, uniformly.

## What the software does about it

Three mechanisms, so this cannot be forgotten rather than merely documented:

1. **Every report says so.** A settlement computed on `secondary` rates carries
   a warning naming the tables, is reported as not fit for filing, and lists
   that as a blocker — even when the arithmetic is otherwise exact.
2. **Production refuses.** With `POLAND_REQUIRE_OFFICIAL_RATES=true`, the engine
   throws `UnverifiedRateException` instead of settling. Set it true in
   production. `docs/DEPLOYMENT.md` requires it.
3. **Preparation refuses.** A document cannot be built from a settlement that is
   not fit for filing, because a document built from unverified figures looks
   exactly like a real one and somebody eventually sends it.

## Doing the verification

```bash
php artisan poland:rate-provenance --todo
```

It prints, for every unverified version: what the source document is, where the
figure currently came from, and **the official URL where it must be confirmed**.
Work that list.

For each figure confirmed, edit the version in `modules/poland/config/rates/`:

```php
'provenance' => [
    'status' => 'official',
    'source_document' => 'Komunikat Prezesa GUS z 20 stycznia 2026 r. ...',
    'source_url' => 'https://stat.gov.pl/...',          // where you actually read it
    'official_source_url' => 'https://stat.gov.pl/...',
    'published_on' => '2026-01-20',
    'checked_on' => '2026-09-20',
    'checked_by' => 'Imię Nazwisko, księgowy',          // a person, not a process
    'notes' => 'Potwierdzono kwotę 9 228,64 zł w komunikacie.',
],
```

`status => 'official'` requires a non-empty `source_url`, enforced in the
constructor: a claim that something was verified officially has to say where,
so that somebody who was not there can re-check it.

Then run the suite. `test_the_shipped_tables_are_honest_about_not_being_officially_verified`
is designed to **fail** once every version is official — that failure is the
signal to delete it and update `docs/OPEN_ITEMS.md`, and it exists so the
transition is deliberate rather than something that quietly happened.

## The list, and what matters most about each

| Table | Version | Confirm | Why it matters |
|---|---|---|---|
| `zus_social` | 2025.1, 2026.1 | Contribution bases and the minimum wage | Wrong base moves every month's ZUS |
| `zus_health` | 2025-02.1, 2026-02.1 | GUS Q4 average wage; **that the 2026 reform really did not take effect**; the flat-tax deduction cap | If the reform did take effect, every 2026 health figure is wrong |
| `pit` | 2025.1, 2026.1 | That the scale parameters really were unchanged for 2026 | "Nothing changed" is a claim about the law and needs checking like any other |
| `vat` | 2025.1, 2026.1 | The 240 000 zł limit, its Dz.U. position, and the transitional rules | Governs whether the taxpayer must register for VAT at all |
| `deadlines` | — | ZUS deadline day for this taxpayer's situation | The 20th is right for a JDG with no employees; a payer with employees files on the 15th |

## Two things that are not rate lookups

**Which ryczałt rate applies.** It depends on what the business actually does
under art. 12 of the ustawa o ryczałcie, and a business may fall under two rates
at once. The engine supports several rates per taxpayer and apportions
deductions between them; it cannot choose them. That is the taxpayer's
declaration, recorded in their profile and in the audit trail.

**Which VAT rate applies to a given product.** Annexes 3 and 10 and, where
there is doubt, a WIS. The rate table lists which rates exist, not which one
belongs on a given line.
