# Isolation from the trading platform

The requirement is absolute: **a failure of accounting cannot stop the trading
bot, and a failure of trading cannot corrupt accounting.** This document records
how that is achieved and how it is checked.

## What is separate

| | Trading | Accounting |
|---|---|---|
| Host | `app.signalmesh.dev` | `account.signalmesh.dev` |
| Codebase | `Sniper-System`, `brother-brain-v2`, `brother_sniper_v7` | this repository |
| Application root | trading platform root | `/srv/accounting/foundation` |
| Database | trading DB | `accounting` DB, own user |
| Queue | trading queue | own queue, own Redis DB index |
| Cache / session | trading Redis | own Redis DB index |
| PHP-FPM pool | trading pool | `php8.5-fpm-accounting.sock` |
| Workers | `sniper-bot.service` and friends | `accounting-queue.service` |
| Storage | trading storage | own storage tree |
| Users | trading accounts | own accounts, own passwords |
| Secrets | trading `.env` | own `.env` |

The two applications share the operating system and, optionally, the database
server process. They share no database, no schema, no user and no credential.
Nothing in this repository opens a connection to anything the trading system
owns.

## What is shared

One hyperlink. SignalMesh gains a navigation item labelled **Accounts** that
points at `https://account.signalmesh.dev`. That is the entire integration
surface for Phase 1. See `docs/SIGNALMESH_NAV_LINK.md` — that change belongs to
Phase 6 and has deliberately **not** been made yet, because the instruction was
to start with Phase 1 and not to modify the trading system to make accounting
work.

## What this application must never receive

- MT5 logins or passwords
- broker credentials of any kind
- bot API secrets or executor tokens
- trading risk configuration
- trading database credentials
- signal payloads, dispatches or decisions

There is no code path that would consume any of them, and `.env.example`
contains none.

## How it is enforced

`bin/check-isolation.sh` fails the build if:

1. any file outside documentation refers to the trading services, brokers, the
   bot or the brain (whole-line comments are excluded so a prohibition is not
   mistaken for a violation);
2. any code refers to the trading host `app.signalmesh.dev` — it may only ever
   be linked to, never called;
3. any secret value is committed;
4. the tax engine grows a dependency on Laravel, which would stop it working
   independently of the application.

Run it in CI and before every deploy. It has been tested in both directions: it
passes on a clean tree and fails when a trading reference is introduced.

## Independent restartability

Each side restarts without the other:

```bash
# Accounting only — the trading bot never notices
systemctl restart php8.5-fpm@accounting accounting-queue.service

# Trading only — accounting keeps serving
systemctl restart sniper-bot.service     # on the trading host
```

The accounting application has no health check that depends on the trading
platform, and the trading platform must gain none that depends on accounting.
Adding one would be a violation of rule 1 in `CLAUDE.md`.

## Authentication

Phase 1 gives the accounting application **its own login**. Trading users and
passwords are not copied, mirrored or synchronised. A shared session store is
explicitly not used.

If single sign-on is wanted later, it must be a deliberate OAuth/OIDC
integration between two applications that remain separate — with its own tokens,
its own scopes and no credential of one becoming a credential of the other.
Coupling the session stores would make an outage of either an outage of both,
which is precisely what this design exists to prevent.
