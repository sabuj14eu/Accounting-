# The SignalMesh "Accounts" navigation item (Phase 6 — NOT YET APPLIED)

This change has **not** been made. It belongs to Phase 6, the instruction was to
start with Phase 1, and modifying the trading platform is exactly what the
isolation requirement forbids doing casually. It is written down here so that
when it is wanted, it is a two-minute change that nobody has to reinvent.

## What the change is

One navigation entry in the SignalMesh platform pointing at the accounting
application. Nothing else. No shared code, no shared session, no API call, no
accounting table in the trading database.

## Where it goes

The SignalMesh platform (`Sniper-System`) renders its navigation from
`app/templates/dash/` on a shared base template. The link belongs in the same
list as the other dashboard sections, following whatever markup that list
already uses.

```html
<!-- Accounts — a plain external link. No coupling of any kind. -->
<a href="https://account.signalmesh.dev"
   target="_blank"
   rel="noopener noreferrer">
    Accounts
</a>
```

`rel="noopener noreferrer"` matters: without `noopener` the opened page gets a
handle on the trading window through `window.opener`, which is a coupling — a
small one, but this project has none and should keep it that way.

## What must NOT be done while adding it

- Do not add an accounting table to the trading database.
- Do not read the accounting database from the trading platform, or the reverse.
- Do not pass a trading session, token or user id in the URL.
- Do not add a health check on either side that depends on the other. A link
  that renders whether or not the target is up is the entire point.
- Do not import any accounting code into the trading platform.

## Verification after applying it

1. The link appears and opens the accounting application.
2. Stop the accounting application. The trading platform still loads, the link
   still renders, and only the click fails.
3. Stop the trading platform. The accounting application still serves.
4. `bin/check-isolation.sh` still passes in this repository.
