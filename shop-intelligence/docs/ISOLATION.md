# Isolation — §2 and §28

Shop Intelligence and the accounting application at `account.signalmesh.dev` are
two applications that happen to be about the same shop. They share nothing.

## What is not shared

| | Accounting application | Shop Intelligence |
|---|---|---|
| Database | `accounting` | `shop_intelligence` |
| Database user | its own, scoped to its own schema | its own, scoped to its own schema |
| Tables | `pl_*` | `shop_*` |
| Migrations | its own | its own |
| Authentication | its own users, its own login | its own users, its own login |
| Session cookie | `account.signalmesh.dev` | `shop.signalmesh.dev`, different cookie name |
| Storage | its own root | `SHOP_STORAGE_ROOT` |
| Queues | its own connection | its own connection |
| Credentials | KSeF token, filing credentials | **none of those exist here** |
| Code | `Poland\` | `Shop\` — no import of `Poland\` anywhere |
| Audit records | `pl_audit_events` | its own |

Neither reads the other's database. Neither shares a Redis index, a session
store or a queue. **Each keeps working when the other is offline**, and for the
analysis core that is not a claim but an observable fact: it has no database
connection and no HTTP client at all, which is why `bin/shop-demo` renders a
full month with neither.

## The only route between them

`Shop\Comparison\AccountsSnapshot` — a container somebody fills by hand with
figures exported from the accounting application, recorded with **who** imported
it, **when**, and **from what**. It computes nothing and connects to nothing.
`AccountsComparison` then compares the two sides and reports the differences.

There is **no write path back**. Not a client, not an API call, not a shared
table. A regression test asserts the absence of any write-shaped method on
`AccountsComparison`, because the guarantee here is an absence, and an absence
has to be tested for or it erodes.

## The trading system

Not referenced, not reachable, not named outside the guards that forbid it. No
MT5, no broker, no executor, no brain, no trading database, no trading
credential. The accounting application's own constitution already forbids this;
Shop Intelligence inherits the prohibition and checks it separately.

## How it is proven rather than asserted

Isolation is the one property that cannot be established by reading a diff once
and trusting it afterwards: it is broken by **adding** something, in any file,
at any time. So it is checked mechanically, in two places, and both run in CI:

```bash
./bin/check-shop-isolation.sh   # 10 checks over src, tests, bin, config
../modules/poland/vendor/bin/phpunit -c phpunit.xml --filter IsolationTest
```

`bin/check-shop-isolation.sh` checks, in order:

1. no accounting database or `pl_` table name anywhere;
2. no import of the `Poland\` tax engine;
3. no KSeF token, client or environment variable;
4. no filing path — no JPK, no e-Deklaracje, no ministry endpoint;
5. no shared session, cookie domain or session store;
6. no write-back function or accounts client;
7. no trading system reference under any of its names;
8. the analysis core opens no connection and makes no outbound call;
9. the application declares its own `composer.json`, `.env.example`, test config;
10. `.env.example` names `shop_intelligence` and no accounting database.

Files that name the forbidden things **in order to forbid them** — the AI
boundary enum, the isolation tests, this document — are excluded by name. That
is not a loophole: a guard that cannot tell a prohibition from a violation is a
guard somebody switches off within a week.

## Current result

```
ISOLATION PROVEN — 10 checks, 0 violations.
```

Measured on 2026-09-07, PHP 8.4.19, commit recorded in the release notes.

## What is still deployment work

The isolation *of the code* is proven. The isolation *of the deployment* — a
separate database created with a separate user and separate grants, a separate
systemd unit, a separate nginx vhost, a separate storage root and a separate
backup — is written down in `.env.example` and not yet executed on the server.
That is the first item in the Fable briefing, and until it is done this section
describes an intention rather than a fact.
