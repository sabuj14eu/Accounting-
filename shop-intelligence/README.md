# Shop Profit Intelligence

A management analysis tool for one food shop. Three pages:

1. **Money** — what came in, what went out, what is still owed, and how each of
   those is known.
2. **Stock** — what the recipes say should have been used against what is
   actually on the shelf.
3. **Profit** — the real result of running the shop, per channel and per
   product, and a ranked answer to "where am I losing money?"

## What it is not

It is **not the accounting service**. It does not calculate tax, does not file
anything, does not touch KSeF, and does not write to the accounting
application. Every figure it produces is a management figure, and it says so on
every screen it appears on.

The relationship with the accounting application at `account.signalmesh.dev` is
one-way and manual: somebody exports the official monthly figures and imports
them here as an `AccountsSnapshot`, recorded with who imported it and from what.
The two are then **compared**. Differences are investigated, never corrected
automatically. See `docs/ISOLATION.md`.

## Running it

The analysis core is framework-free — no Composer install, no database, no
Laravel. It runs from a plain PHP file:

```bash
php bin/shop-demo                 # a complete worked month, rendered as text
./bin/check-shop-isolation.sh     # the §28 isolation audit, 10 checks
../modules/poland/vendor/bin/phpunit -c phpunit.xml
```

## Reading order

| Document | What it settles |
|---|---|
| `docs/BANKING_UX.md` | what "work like a banking app" means here, as a design |
| `docs/ISOLATION.md` | the §28 audit: what is shared (nothing) and how that is proven |
| `docs/REGRESSION_MAP.md` | the 27 regression tests and the rule each one pins |
| `../docs/FABLE_BRIEFING_2026-09-08.md` | the handover: state, gaps, and what to do next |

## The one idea underneath everything

**A number carries its evidence in the type system, not in a label beside it on
the screen.**

`EvidenceType` decides `Provenance` and `Certainty`; there is no way for a
caller to set them. So a cash payment the owner remembered cannot be recorded as
ACTUAL and bank-confirmed, a scheduled rent cannot become a paid rent because
somebody clicked a button, and a total built from both cannot be rendered
without showing which is which.

Everything else in this application is a consequence of that.
