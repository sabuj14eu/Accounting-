# KSeF — New User Guide (setup manual)

**Who this is for:** a Polish sole trader (JDG) or their bookkeeper who has
never set up KSeF in this application before. No programming knowledge assumed.

**What this document is:** a description of **what the application does today**,
verified line by line against the source code on 2026-09-09. It is not a plan
and not a wish list. Where a step in a normal KSeF setup guide does **not exist
in this application**, this guide says so plainly in a box like this one instead
of describing a screen you will not find:

> ⚠️ **NOT IN THIS VERSION.** Text like this marks something that does not exist
> in the application yet. Every one of these is recorded in
> `docs/OPEN_ITEMS.md` so it is not forgotten.

**The single most important sentence in this guide:**

> 🔴 **This application does not send anything to KSeF. It never issues an
> invoice, never submits an invoice, and never files a return.** KSeF here is a
> *read-only inbox* for the purchase invoices other companies issued to you.
> Verified in code: `Poland\Ksef\KsefScope::allowed()` returns `InvoiceRead` and
> nothing else, and `Poland\Reporting\FilingChannel::automated()` returns
> `false` for every channel including KSeF.

**And the second most important:**

> 🔴 **The KSeF connection is not switched on in this deployment.** The part of
> the software that actually speaks HTTP to the Ministry's servers has not been
> written yet. Everything around it — the invoice reader, the duplicate guard,
> the encrypted token store, the sync bookmark — is built and tested, but the
> pipe itself is missing. The application knows this about itself and says
> `NOT CONNECTED` on the dashboard rather than pretending. See §5 and §11.

Read §1, §5 and §9 even if you read nothing else.

---

## Table of contents

1. [What KSeF is](#1-what-ksef-is)
2. [Before you start — checklist](#2-before-you-start--checklist)
3. [How to open KSeF in this application](#3-how-to-open-ksef-in-this-application)
4. [KSeF configuration, step by step](#4-ksef-configuration-step-by-step)
5. [Status meanings](#5-status-meanings)
6. [Your first test invoice](#6-your-first-test-invoice)
7. [TEST → PRODUCTION](#7-test--production)
8. [Troubleshooting](#8-troubleshooting)
9. [Security — read this twice](#9-security--read-this-twice)
10. [Quick-start checklist](#10-quick-start-checklist)
11. [Appendix A — what was verified, and where](#appendix-a--what-was-verified-and-where)
12. [Appendix B — glossary](#appendix-b--glossary)

---

## 1. What KSeF is

### 1.1 The name

**KSeF** = **K**rajowy **S**ystem **e**-**F**aktur — the National e-Invoicing
System, run by the Polish Ministry of Finance (Ministerstwo Finansów).

### 1.2 What it is used for

Think of KSeF as a **government post office for invoices**.

When one Polish company invoices another through KSeF:

1. The seller sends the invoice to KSeF instead of emailing a PDF.
2. KSeF gives it a permanent identifier — the **KSeF number** — and keeps a copy
   forever.
3. The buyer collects it from their KSeF inbox.

Because the Ministry holds the original, both sides are looking at the *same*
document. There is no "my copy says 1 230 zł, yours says 1 320 zł".

### 1.3 Why this accounting system connects to it

For exactly one reason: **to collect your incoming purchase invoices
automatically**, so your monthly costs and input VAT are not limited to what you
remembered to type in.

That is the whole ambition. In the application's own words, on screen:

> *"System NIE pobiera faktur zakupowych. To nie znaczy, że faktur nie ma —
> znaczy, że ich nie widzimy."*
> ("The system is NOT downloading purchase invoices. That does not mean there are
> no invoices — it means we cannot see them.")

That sentence is deliberate and it is the design principle of the whole
integration: **the system never lets "we could not check" look like "there is
nothing there."** A missing connection could quietly understate your costs, and
understated costs mean overstated tax.

> ⚠️ **NOT IN THIS VERSION — issuing and submitting invoices.**
> This application will never send your sales invoices to KSeF, and this is not
> an oversight to be fixed later by configuration. The permission to issue
> invoices in a taxpayer's name (`InvoiceWrite`) is written into the code
> *specifically so it can be refused by name*:
>
> ```
> Zakres KSeF "InvoiceWrite" nie jest dozwolony w tej aplikacji.
> Dozwolone: InvoiceRead. Wystawianie faktur w imieniu podatnika to inne
> uprawnienie niż odczyt jego skrzynki i wymaga świadomej zmiany w kodzie,
> nie w konfiguracji.
> ```
>
> Issuing an invoice in somebody's name is a materially different act from
> reading their inbox. It would require a deliberate code change with a stated
> reason — never a setting somebody flips.

### 1.4 TEST versus PRODUCTION

The Ministry runs more than one KSeF. This application knows three environments
(`Poland\Ksef\KsefEnvironment`):

| Value in config | Name shown by the code | What it is |
|---|---|---|
| `test` | *Środowisko testowe KSeF* | The Ministry's practice system. Invoices here are pretend. Nothing has any legal or tax effect. |
| `demo` | *Środowisko demo KSeF* | A second non-production environment. Also pretend. |
| `production` | **KSeF PRODUKCYJNY — dane rzeczywiste** | The real thing. Real invoices, real tax consequences, real audit trail. |

Three things follow, and all three are enforced by the software, not by
discipline:

1. **A token issued for one environment cannot be used on another.** They are
   separate cases in the code and the credential row stores which environment it
   belongs to (`pl_ksef_credentials.environment`, unique per taxpayer +
   environment).
2. **Production may never use a simulated connection.** If someone configures
   production with the fake transport, the application refuses to start that
   path at all — it throws an error rather than running in a degraded mode.
   (`Poland\Ksef\TransportGate::assertUsable()`.)
3. **A new user always starts in TEST.** The shipped default is
   `KSEF_ENVIRONMENT=test`, and it is the right default. See §7.

### 1.5 What "CONNECTED" means

`CONNECTED` is a statement **about the software**, not about your invoices. It
means, precisely:

> A real HTTP connection to KSeF is enabled, in a named environment, holding
> `InvoiceRead` permission — so the purchase-invoice download can run.

It does **not** mean:

- that a sync has run,
- that any invoice was downloaded,
- that your month is complete,
- that anything was sent to KSeF (nothing ever is).

### 1.6 What "NOT CONFIGURED" / "NOT CONNECTED" means

You will meet two closely related phrasings. Both mean *the system cannot see
your KSeF inbox*, and neither means *your inbox is empty*.

- **`NOT CONNECTED`** (dashboard integration panel) — the transport is switched
  off or not implemented. On-screen detail:
  *"Transport HTTP do KSeF nie jest włączony. System NIE pobiera faktur
  zakupowych. To nie znaczy, że faktur nie ma — znaczy, że ich nie widzimy."*

- **"Integracja z KSeF nie jest skonfigurowana"** (raised when something tries to
  sync anyway) — the client refuses rather than returning an empty list:
  *"Integracja z KSeF nie jest skonfigurowana — nic nie zostało pobrane. To NIE
  oznacza, że nie ma faktur zakupowych: oznacza, że system ich nie widzi."*

> 💡 **Why the application is so insistent about this.** An empty result and a
> broken connection look identical on a screen. If the system showed "0 purchase
> invoices" when it simply could not reach KSeF, your costs would be understated,
> your VAT deduction would be understated, and your tax bill would be *too high*
> — and nothing on the screen would tell you.

---

## 2. Before you start — checklist

Collect all of this **before** touching any configuration. Half of it comes from
your registration documents; the rest you create in KSeF itself.

### 2.1 Your business identity

| # | What you need | Where it comes from | Where it goes in this system |
|---|---|---|---|
| 1 | **NIP** (10 digits) | CEIDG entry / your registration | Taxpayer profile (`pl_tax_profiles.nip`) **and** the KSeF credential row (`pl_ksef_credentials.nip`) |
| 2 | **Business name** exactly as registered | CEIDG | Taxpayer profile (`name`) |
| 3 | **REGON** (optional, 9 or 14 digits) | GUS / CEIDG | Taxpayer profile (`regon`) |
| 4 | **Business start date** (year, month, and day of month) | CEIDG | Taxpayer profile — it decides whether your first month is prorated |

> ⚠️ **NOT IN THIS VERSION — company address.** The taxpayer profile table
> (`pl_tax_profiles`) has **no address fields**: no street, no postcode, no city,
> no country. If a KSeF setup guide tells you to enter your registered address,
> there is nowhere to put it here. Recorded in `docs/OPEN_ITEMS.md`.

### 2.2 Your tax setup

These are not optional extras — every one of them changes the tax the system
calculates, which is why they live in explicit columns rather than a free-text
note.

| # | What you need | Notes |
|---|---|---|
| 5 | **PIT regime** — ryczałt / scale (skala) / flat tax (podatek liniowy) | `pit_regime` |
| 6 | **Ryczałt rate**, if on ryczałt | `lump_sum_rate`. ⚠️ Which rate applies to your PKD activity is *your accountant's decision* — the example profile ships 3% as an illustration, not as advice. A business selling goods *and* providing services may fall under two rates at once. |
| 7 | **VAT status** — VAT payer, or exempt (zwolniony) | `vat_status`. Decides whether your revenue is taken net or gross. |
| 8 | **VAT settlement frequency** — monthly / quarterly | `vat_settlement`. See the limitation note in §2.4. |
| 9 | **ZUS scheme** — full / preferential (preferencyjny) / Mały ZUS Plus / ulga na start | `zus_scheme` |
| 10 | **Sickness insurance** yes/no, **accident rate** | `sickness_insurance`, `accident_rate` |
| 11 | **Mały ZUS Plus base**, if applicable | `maly_zus_plus_base` — you supply it; the system validates it against statutory bounds but does not compute it |
| 12 | **Previous year's revenue**, if your health-contribution band depends on it | `previous_year_revenue` |

### 2.3 Your KSeF authorisation

| # | What you need | How you get it |
|---|---|---|
| 13 | **A KSeF authorisation token** scoped to **InvoiceRead only** | You generate it yourself, inside KSeF, using your own qualified electronic signature / trusted profile (profil zaufany) / ePUAP. **Not in this application.** |
| 14 | **The KSeF base URL** for the environment you chose | The Ministry's current publication. It is configuration, never hardcoded, because the Ministry has moved these addresses before. |
| 15 | **The token's expiry date**, if you set one | Stored as `token_valid_until` so the system can say "your token expired on 14.03.2026" instead of "error" |

> 🔒 **Your ordinary KSeF password is never stored by this application and must
> never be entered into it.** Authentication uses a token you generate *for this
> application specifically*, and which you can revoke on its own without changing
> anything else about your KSeF access. This is a design decision, documented in
> `KsefCredentialModel`.

### 2.4 What you need for invoice defaults

> ⚠️ **NOT IN THIS VERSION — invoice defaults.** There is no invoice-issuing
> feature, therefore there are no invoice defaults: no default payment terms, no
> default due-date rule, no default bank account, no default VAT rate for new
> invoices, no default invoice numbering series, no default unit of measure.
> None of these fields exist anywhere in the database, the models or the screens.
>
> The nearest thing that does exist is a **default VAT designation for
> cash-register sales**, and it is not configurable — it is derived: if your
> profile says you settle VAT, the dashboard uses `0.23`; if you are VAT-exempt,
> it uses `zw`. The controller would accept an override, but the form does not
> render a field for one. (`DashboardController::storeSales()`.)

### 2.5 🔴 Before you go further — the credentials rule

**Never share a KSeF token, password, private key, or qualified-signature PIN
with anybody. That includes:**

- support staff,
- your bookkeeper,
- the developers of this application,
- a chat window (this one included),
- an email, a screenshot, a ticket, or a WhatsApp message.

A KSeF token is a **bearer credential**: whoever holds it *is* you, as far as
KSeF is concerned, for as long as it is valid. Nobody legitimate will ever ask
you for it. If someone does, that alone is the answer.

Full details and what to do if a token leaks: **§9**.

---

## 3. How to open KSeF in this application

### 3.1 What the request assumed

> ⚠️ **NOT IN THIS VERSION — the KSeF admin section.**
> There is **no** `Admin` menu, **no** `Tax & Compliance` section and **no**
> `KSeF` page in this application. That navigation path does not exist and
> cannot be typed into the address bar, because there is no route behind it.
>
> The application's complete, exhaustive route list is nine routes, all defined
> in `modules/poland/routes/web.php`. **None of them is a KSeF route.** They are
> listed in full in §3.3 below so you can see for yourself.

### 3.2 Where KSeF actually appears on screen

KSeF has exactly **one** appearance in the user interface: a **row in the
integration status table on the main dashboard**.

**Step by step:**

1. Open the application at **`https://account.signalmesh.dev/`**
   *(the module's screens live under the `poland` prefix — see §3.3)*.
2. Log in. (The accounting application has its own login. It shares no session
   and no credential with any other system.)
3. Go to **`https://account.signalmesh.dev/poland`** — the main screen,
   titled ***"Co muszę zapłacić"*** ("What do I have to pay").
4. Scroll down to the card headed ***"Stan integracji"*** ("Integration status").
5. The first row of that table is **KSeF**.

**What that row shows** — three columns:

| Column heading | Meaning |
|---|---|
| *Integracja* | The integration's name — `KSeF` |
| *Status* | A coloured pill: green if working, red if not. See §5 for every value. |
| *Co to znaczy* | Plain-language explanation, plus a `→` line telling you the next step when it is not working |

```
┌─ Stan integracji ──────────────────────────────────────────────────────┐
│ Integracja      │ Status         │ Co to znaczy                        │
├─────────────────┼────────────────┼─────────────────────────────────────┤
│ KSeF            │ [NOT CONNECTED]│ Transport HTTP do KSeF nie jest      │
│                 │                │ włączony. System NIE pobiera faktur  │
│                 │                │ zakupowych. To nie znaczy, że faktur │
│                 │                │ nie ma — znaczy, że ich nie widzimy. │
│                 │                │ → Zaimplementuj transport wg         │
│                 │                │   aktualnej oficjalnej specyfikacji  │
│                 │                │   i włącz KSEF_TRANSPORT_ENABLED.    │
├─────────────────┼────────────────┼─────────────────────────────────────┤
│ Import wyciągów │ [AVAILABLE]    │ CSV, MT940 i camt.053 …             │
│ bankowych       │                │                                     │
├─────────────────┼────────────────┼─────────────────────────────────────┤
│ OCR / odczyt    │ [NOT AVAILABLE]│ Brak mechanizmu odczytu tekstu …    │
│ tekstu          │                │                                     │
├─────────────────┼────────────────┼─────────────────────────────────────┤
│ Stawki          │ [NOT VERIFIED] │ Stawki pochodzą ze źródeł wtórnych …│
│ podatkowe       │                │                                     │
├─────────────────┼────────────────┼─────────────────────────────────────┤
│ Wysyłka do      │ [DISABLED]     │ System niczego nie wysyła do ZUS,   │
│ organów         │                │ urzędu skarbowego ani KSeF.         │
└─────────────────┴────────────────┴─────────────────────────────────────┘
```

*(Placeholder for a screenshot of the "Stan integracji" card. The layout above
is drawn from `resources/views/dashboard.blade.php` lines 231–256 and the exact
strings from `Poland\Certainty\IntegrationStatus`.)*

> 💡 **Read the last row every time.** *"Wysyłka do organów (KSeF / JPK /
> pisma) — DISABLED — System niczego nie wysyła do ZUS, urzędu skarbowego ani
> KSeF. Każdy kanał wysyłki zgłasza błąd zamiast wysyłać."* This row is
> hard-coded to always say DISABLED, and a test asserts it can never say
> anything else.

### 3.3 The complete route list

These nine routes are everything the module serves. `{period}` is always
`YYYY-MM` (for example `2026-08`).

| Method | URL | What it does |
|---|---|---|
| `GET` | `/poland` | Dashboard — *Co muszę zapłacić*. **The KSeF status row is here.** |
| `POST` | `/poland/sprzedaz` | Record the month's cash-register sales total |
| `POST` | `/poland/koszty` | Record the month's costs and input VAT |
| `GET` | `/poland/raport/{period}` | The month's report |
| `POST` | `/poland/raport/{period}/generuj` | Generate a new version of the report |
| `POST` | `/poland/raport/{period}/platnosc` | Mark an obligation as paid |
| `POST` | `/poland/raport/{period}/zamknij` | Close the month |
| `POST` | `/poland/raport/{period}/otworz` | Reopen the month |
| `GET` | `/poland/raport/{period}/historia` | Version history for the month |

*(The `/poland` prefix is configurable via `POLAND_ROUTE_PREFIX`; `poland` is
the shipped default. All nine sit behind the `web` and `auth` middleware — you
must be logged in.)*

### 3.4 The three actions the request asked about

| Requested | Exists? | Reality |
|---|---|---|
| **Status and connection test** | Status **yes**, test **no** | The status row on `/poland` exists and is accurate. There is **no "Test connection" button** anywhere in the application. |
| **Configuration** (a KSeF settings page) | **No** | KSeF is configured through the server's `.env` file plus a row in the `pl_ksef_credentials` database table. Neither is reachable from any screen. |
| **Outgoing invoices** (a page listing invoices you send to KSeF) | **No** | No such page, no such route, no such feature. The database *does* have a `direction` column that can hold `outgoing`, but that only records invoices *downloaded from* KSeF where you happen to be the seller — it is never a submission queue. |

---

## 4. KSeF configuration, step by step

> ⚠️ **NOT IN THIS VERSION — the five-step wizard.**
> There is no configuration wizard in this application. No Step 1, no Step 2, no
> "Next" button, no progress bar, no "Test and power on" screen. There is no KSeF
> settings form of any kind. This was verified by reading every route, every
> controller and all three Blade templates in the module.
>
> What follows documents **how KSeF configuration actually reaches the system
> today**, mapped onto the five headings that were requested, so you can see
> exactly which piece of information ends up where. Every step below is
> **server-side work performed by whoever administers the deployment** — it is
> not something a customer can do from a browser.

### Step 1 — Environment

**Where it lives:** the `KSEF_ENVIRONMENT` setting in the server's `.env` file,
and the `environment` column of the `pl_ksef_credentials` row.

**Accepted values:** `test`, `demo`, `production`. Anything else silently falls
back to `test` (`KsefEnvironment::tryFrom(...) ?? KsefEnvironment::Test`) — the
safe direction to fail in.

**Shipped default:** `KSEF_ENVIRONMENT=test`.

**Explaining TEST.** The Ministry's practice system. Every invoice in it is
fictional. Nothing you do there reaches any tax authority, appears in any
register, or has any legal effect. It exists so you can be wrong safely.

**Explaining PRODUCTION.** The live national system. Use it only when *all* of
the following are true:

- a real HTTP transport exists and has been tested (see §7 — today it does not),
- a full sync has been run in TEST and the results checked against invoices you
  independently know you received,
- your taxpayer profile has been verified figure by figure,
- your token is a genuine production token with `InvoiceRead` scope,
- your accountant knows you are switching.

**Why a new user tests first.** Because in TEST a mistake is a lesson, and in
production a mistake is an entry in your tax records. There is no cost to being
slow here and no way to un-download a wrong month into a real settlement.

> 🔒 **The rule production cannot bend.** The `TransportGate` refuses at
> construction if production is paired with the simulated (`fake`) transport:
>
> *"Środowisko PRODUKCYJNE nie może używać atrapy transportu KSeF. Ciche
> przejście z prawdziwego KSeF na atrapę zamieniłoby komunikat 'brak połączenia'
> w twierdzenie 'brak faktur' — a to jest zdanie o miesiącu podatnika, nie o
> systemie."*
>
> It throws an exception. It does not warn and continue.

### Step 2 — Seller details

**Where it lives:** the `pl_tax_profiles` table (the taxpayer profile), created
by whoever sets up the deployment. There is no screen for it.

> ⚠️ **NOT IN THIS VERSION — the taxpayer profile form.** There is no "add
> taxpayer" or "edit taxpayer" screen. The dashboard *reads* the first profile in
> the table (or the one named by `?profile=` in the URL) and shows this error if
> there is none: **"Najpierw skonfiguruj profil podatnika."** ("First configure
> the taxpayer profile.") — but it gives you nowhere to configure it.

**Every field that actually exists**, from the migration
`2026_09_07_000100_create_poland_tax_profiles_table.php`:

| Field | What belongs in it | Safe example | Common mistake |
|---|---|---|---|
| `name` | Your business name exactly as registered in CEIDG | `Jan Kowalski Handel i Usługi` | Using a trading name that differs from the registered one — KSeF matches on NIP, but your reports will not match your documents |
| `nip` | 10-digit NIP, digits only | `1234567890` | Entering it with dashes or the `PL` prefix inconsistently. The system strips non-digits when comparing NIPs, but be consistent anyway |
| `regon` | 9 or 14 digits, optional | `123456789` | Confusing REGON with NIP |
| `company_id` | Optional link to the host ERP's company record | *(usually empty)* | — |
| `pit_regime` | Your income-tax regime | `ryczalt` | Choosing the regime you *want* rather than the one you actually registered |
| `lump_sum_rate` | Your ryczałt rate, if on ryczałt | `0.0300` (= 3%) | 🔴 **The big one.** Picking a rate from an example. The rate depends on your actual PKD activity under art. 12, and getting it wrong changes your tax by multiples. Ask your accountant. |
| `vat_status` | VAT payer or exempt | `vat_payer` / `exempt` | Saying "exempt" when you are registered — this makes the system treat gross takings as revenue and **overstates a ryczałt base by 23%** |
| `vat_settlement` | `monthly` or `quarterly` | `monthly` | See the limitation below |
| `zus_scheme` | Your ZUS scheme | `preferential` | Leaving it on the full scheme while on ulga na start |
| `sickness_insurance` | Whether you pay voluntary sickness insurance | `true` | — |
| `accident_rate` | Your accident-insurance rate | `0.0167` | Using last year's rate |
| `maly_zus_plus_base` | Your declared Mały ZUS Plus base | *(only if applicable)* | Expecting the system to calculate it — it validates, it does not derive |
| `business_started_at` | `YYYY-MM` of when you started | `2026-03` | — |
| `business_started_on_day` | Day of the month you started | `17` | Leaving it at `1` when you started mid-month — an incomplete first month prorates social contributions by days |
| `deduction_basis` | How contributions are attributed to months | `accrued_for_month` | — |
| `health_band_from_previous_year` | Whether your health band uses last year's revenue | `false` | — |
| `previous_year_revenue` | Last year's revenue, if the band needs it | `180000.00` | — |
| `reduce_health_band_by_social` | Whether social contributions reduce the health band | `true` | — |
| `cash_register_letters` | Your cash register's VAT letter mapping (JSON) | `{"A":"0.23","B":"0.08"}` | — |

**Fields a KSeF guide would ask for and this table does not have:**
street, building number, flat number, postcode, city, voivodeship, country code,
email, phone, bank account number, PKD codes.

> ⚠️ Two known limitations of this profile, both already recorded in
> `docs/OPEN_ITEMS.md`:
> - **Multi-rate ryczałt**: the calculation engine supports several ryczałt rates
>   per taxpayer, but the data entry only accepts one. Fine for a
>   single-activity business, wrong for a mixed one.
> - **Quarterly VAT**: the profile carries the frequency and the report names the
>   right structure, but settlement runs monthly throughout.

### Step 3 — Permissions and token

**What the authentication information is for.** It proves to KSeF that this
application is allowed to read *your* invoice inbox. Nothing more. The token
carries a permission scope, and this application accepts exactly one:
**`InvoiceRead`**.

**Where you obtain it.** In KSeF itself, not here. You authenticate to KSeF with
your own qualified electronic signature, trusted profile (profil zaufany) or
ePUAP, and generate an authorisation token for this application. Give it
**InvoiceRead and nothing else**. If the interface offers you `InvoiceWrite` or
`CredentialsManage`, do not tick them — this application will refuse a token
carrying them (see below) and a wider permission than needed is a wider blast
radius if it ever leaks.

**How it is entered into the application today.**

> ⚠️ **NOT IN THIS VERSION — the token entry form.** There is no field, no
> screen and no upload. The token reaches the system as a row in the
> `pl_ksef_credentials` table, written by an administrator, with these columns:
>
> | Column | Meaning |
> |---|---|
> | `tax_profile_id` | Which taxpayer this token belongs to |
> | `environment` | `test` / `demo` / `production` — unique together with the profile, so one token can never be used against the wrong environment |
> | `nip` | The NIP the token authenticates for |
> | `base_url` | The KSeF endpoint for that environment |
> | `token_encrypted` | The token itself — **encrypted at rest**, hidden from JSON and array output, redacted in debug output |
> | `scope` | `InvoiceRead`. Stored so a widened scope is visible in the data, not only in whatever the API happens to accept |
> | `token_valid_until` | Optional expiry, so the system can say *"Token KSeF wygasł 14.03.2026"* rather than just "error" |
> | `last_verified_at` | When the credential was last confirmed working |
> | `enabled` | Defaults to **`false`** — a credential is off until somebody deliberately turns it on |
> | `last_error` | The last failure, in words |
>
> `.env` also carries `KSEF_BASE_URL`, `KSEF_NIP` and `KSEF_TOKEN` placeholders,
> all shipped empty.

**Security precautions built into the code** — you do not have to trust these,
they are pinned by ten tests in `tests/Unit/CredentialSecurityTest.php`:

1. The token is **encrypted at rest** by the model's cast.
2. It is excluded from the model's array form and its JSON form.
3. It is redacted in `print_r`, `var_dump`, `__debugInfo` and serialisation.
4. There is **exactly one** way to read the real value — `revealToken()` — so
   every call site is a single greppable word in code review.
5. `revealToken()` **refuses** if the stored scope is not `InvoiceRead`. A row
   edited directly in the database to say `InvoiceWrite` cannot be used at all.
6. An open session redacts its token in `__toString`, `jsonSerialize` and
   `__debugInfo`; reading it requires `reveal()`, at one call site.
7. No exception message anywhere in the KSeF layer interpolates a token — this
   is asserted by a test that scans the source.
8. A test asserts **no credential value is committed to the repository**.

> 🔒 **What this means for you:** the application is built so that your token
> cannot end up in a log file, an error page, a stack trace or a support export.
> Do not undo that by putting it somewhere it does not belong yourself. §9.

### Step 4 — Default invoice details

> ⚠️ **NOT IN THIS VERSION — invoice defaults.** As explained in §2.4: there is
> no invoice-issuing feature, so there are no invoice defaults to configure. No
> payment terms, no due-date rule, no numbering series, no default bank account,
> no default VAT rate for issued invoices, no default currency for issued
> invoices, no unit of measure. These fields do not exist in the database.

**What genuinely does affect how downloaded invoices are handled**, since a
newcomer will reasonably ask:

| Setting | Default | What it does |
|---|---|---|
| `KSEF_LOOKBACK_DAYS` | `45` | How many days back a routine sync looks. Deliberately wider than a month, so a run missed while the server was down still catches up. |
| `KSEF_MAX_INVOICES_PER_RUN` | `500` | Cap on one run. Hitting it does **not** lose data: the run is marked incomplete, the bookmark does not advance, and the next run resumes. |
| `POLAND_MATCH_WINDOW_DAYS` | `45` | How far apart a bank payment and its invoice may be and still be considered a match |
| `POLAND_AUTO_BOOK_CONFIDENCE` | `0.9` | A match suggestion below this is never booked automatically |
| VAT designation for cash sales | derived | `0.23` if you settle VAT, `zw` if exempt. Derived from your profile, not stored as a setting, and **not editable from the form** — the field is not rendered |

**How a downloaded invoice is classified.** When an invoice arrives, the system
compares NIPs (digits only) to decide direction: if **your** NIP is the buyer's,
it is `incoming` (a cost); if yours is the seller's, it is `outgoing`. It is then
filed to a month based on the invoice date, and flagged
**`needs_review`** if any of these is true:

- required fields could not be read → *"Nie odczytano wszystkich wymaganych pól: …"*
- net + VAT does not equal gross → *"Suma netto i VAT nie zgadza się z kwotą brutto na fakturze."*
- it is a correction invoice (type contains `KOR`) → *"Faktura korygująca — wymaga przypisania do faktury pierwotnej."*

### Step 5 — Test and power on

> ⚠️ **NOT IN THIS VERSION — the test-and-power-on screen.** There is no "Test
> connection" button, no "Enable KSeF" toggle, no success banner and no failure
> banner. Nothing in the interface starts a KSeF sync.
>
> The sync itself is real, built and tested — `KsefIngestService::sync()` — and
> it is called by `MonthCloseService::close()` as the first of ten month-close
> steps. But **`MonthCloseService` is registered in the service container and
> never invoked**: no route calls it and no Artisan command calls it. The
> module's three commands are `poland:report`, `poland:verify-rates` and
> `poland:rate-provenance`; none of them touches KSeF. Recorded in
> `docs/OPEN_ITEMS.md`.

**What you would check before a test, once one is possible:**

1. Taxpayer profile exists, NIP correct, regime and VAT status correct.
2. `KSEF_ENVIRONMENT=test`.
3. A credential row exists for that profile **and that environment**, with a
   token, `scope = InvoiceRead`, `enabled = true`, and either no expiry or an
   expiry in the future — this is exactly the set of conditions
   `KsefCredentialModel::isUsable()` checks.
4. `KSEF_TRANSPORT_ENABLED=true` and `KSEF_TRANSPORT` set to a real transport.
5. `KSEF_BASE_URL` matching the environment.

**What success would mean.** That a session opened, a page of invoice metadata
came back, and each invoice's XML was fetched and stored. The run reports itself
in this shape:

> *"Pobrano 12 nowych faktur, 3 duplikatów pominięto, 0 błędów."*
> ("Downloaded 12 new invoices, skipped 3 duplicates, 0 errors.")

A run is **clean** only when it completed *and* had zero failures. A run that
imported 3 and failed on 7 is not a successful run, and the summary is built so
it cannot be reported as one.

**What failure would mean.** The run stops and says *why*, and — this is the
important part — the sync bookmark **does not advance**. The next run
re-requests the same window. Duplicates are caught by a unique database index;
gaps would be caught by nothing at all, which is why the system prefers
re-downloading to skipping ahead.

> *"Synchronizacja PRZERWANA: [powód]. Pobrano 5, duplikaty 0, błędy 2.
> Wznowienie od 2026-08-01 — nic nie zostało pominięte."*

**Successful test ≠ connected.** These are three different things and confusing
them is the classic new-user mistake:

| | What it proves |
|---|---|
| **A successful connection** | The software can reach KSeF and authenticate. Nothing about your data. |
| **A successful sync run** | Invoices were downloaded *in that window*. Nothing about other windows, and nothing about invoices flagged `needs_review`. |
| **A complete month** | Every invoice for the month is present and reviewed. This system never claims it — a month whose KSeF step was skipped or failed is marked `BLOCKED` with the caveat *"Synchronizacja z KSeF niedostępna — faktury zakupowe mogą być niekompletne."* |

---

## 5. Status meanings

Every status below was read out of the source. Nothing here is invented.

### 5.1 Integration panel statuses (the KSeF row on `/poland`)

| Status | Colour | What it means | On-screen detail (Polish, verbatim) | What to do |
|---|---|---|---|---|
| **`NOT CONNECTED`** | red | The transport is off or not implemented. **This is the current state of this deployment.** | *"Transport HTTP do KSeF nie jest włączony. System NIE pobiera faktur zakupowych. To nie znaczy, że faktur nie ma — znaczy, że ich nie widzimy."* | Nothing you can do from the interface. The next step shown is for the administrator: implement the transport and enable `KSEF_TRANSPORT_ENABLED`. Enter purchase invoices manually via *Koszty* meanwhile. |
| **`TEST TRANSPORT`** | red *(deliberately — it is not operational)* | A **simulated** connection is in use. Data did not come from KSeF. | *"Używana jest ATRAPA transportu. Dane nie pochodzą z KSeF i nie mogą być podstawą rozliczenia."* | Never settle a month on this. Switch to a real transport before filing. |
| **`UNAVAILABLE`** | red | The transport is real and enabled, but this taxpayer cannot sync — bad credential, expired token, wrong scope, or disabled integration. | *"Synchronizacja niedostępna: [reason]"* | Read the reason. §5.2 decodes every possible one. |
| **`CONNECTED`** | green | Real transport, enabled, credentials usable. | *"Pobieranie faktur zakupowych działa (InvoiceRead)."* | Nothing. This is the goal state. |

> 💡 **`NOT CONFIGURED` is not one of them.** The request asked about a status
> called `NOT CONFIGURED`. The application does not use that wording for the
> integration panel — it says `NOT CONNECTED`, and the phrase *"Integracja z KSeF
> nie jest skonfigurowana"* appears as an **error message** when something tries
> to use the unconfigured client. Both mean the same thing to you.

### 5.2 Reasons a sync cannot run

These appear inside `UNAVAILABLE`, and are produced by
`KsefIngestService::unavailableReason()` and `KsefCredentialModel::unusableReason()`.

| Message (verbatim) | English | Root cause | What to check | What to do |
|---|---|---|---|---|
| *"Klient KSeF nie jest skonfigurowany (brak adresu środowiska lub implementacji transportu)."* | KSeF client not configured — no environment address or no transport implementation | `UnconfiguredKsefClient` is bound, i.e. the HTTP transport does not exist | `KSEF_BASE_URL`, `KSEF_TRANSPORT`, `KSEF_TRANSPORT_ENABLED` | Administrator task. This is the current deployment state. |
| *"Nie skonfigurowano dostępu do KSeF dla tego podatnika."* | No KSeF access configured for this taxpayer | No row in `pl_ksef_credentials` for this profile | Whether a credential row exists at all | Administrator must create the credential row |
| *"Integracja KSeF jest wyłączona dla tego podatnika."* | KSeF integration disabled for this taxpayer | `enabled = false` (the shipped default) | The `enabled` column | Enable it deliberately, after checking everything else |
| *"Nie zapisano tokenu KSeF."* | No KSeF token saved | `token_encrypted` is null or empty | Whether a token was ever stored | Generate an InvoiceRead token in KSeF and have it stored |
| *"Zakres «X» nie jest dozwolony — wymagany InvoiceRead."* | Scope X not allowed — InvoiceRead required | The stored scope is not `InvoiceRead` | The `scope` column | Generate a token with **InvoiceRead only** |
| *"Token KSeF wygasł DD.MM.YYYY."* | KSeF token expired on … | `token_valid_until` is in the past | The expiry date shown | Generate a fresh token in KSeF. **Revoke the old one.** |

### 5.3 Authentication and connection failures

| What happens | Where it comes from | What it means |
|---|---|---|
| *"Integracja z KSeF nie jest skonfigurowana — nic nie zostało pobrane. To NIE oznacza, że nie ma faktur zakupowych: oznacza, że system ich nie widzi. Skonfiguruj adres środowiska i token (poland.ksef), potem uruchom synchronizację."* | `UnconfiguredKsefClient` — thrown on any attempt to open a session, list invoices or fetch XML | The refusal that stands in for a real client. **This is what happens today if anything tries to sync.** |
| *"Synchronizacja z KSeF niedostępna: [cause]. To NIE jest informacja o braku faktur — system nie zdołał ich sprawdzić."* | `TransportGate::reportFailure()` | Any transport-level failure — network, timeout, HTTP error — wrapped so it can never be read as "no invoices". Authentication failures surface here, **with no credential material in the message**. |
| *"Środowisko PRODUKCYJNE nie może używać atrapy transportu KSeF. …"* | `TransportGate::assertUsable()` | A configuration error: production paired with a simulated transport. The application refuses to run rather than degrade. |
| *"Zakres KSeF «InvoiceWrite» nie jest dozwolony w tej aplikacji. …"* | `KsefScope::assertAllowed()` | Something asked for a write permission. Structurally impossible to grant here. |

> ⚠️ **`missing taxpayer profile` — the wording that actually appears.**
> Not a KSeF status. It is a form error on the dashboard:
> **"Najpierw skonfiguruj profil podatnika."** ("First configure the taxpayer
> profile.") It appears when you try to record sales or costs with no profile in
> the system. The KSeF row simply reports the transport state, without a
> per-taxpayer reason, when there is no profile to check.

### 5.4 Statuses on a downloaded invoice

Once invoices exist in `pl_ksef_documents`:

| Column | Values | Meaning |
|---|---|---|
| `processing_status` | `imported` / `needs_review` | Whether a person needs to look at it |
| `needs_review` | true / false | Set when fields are missing, totals disagree, or it is a correction |
| `review_reason` | text | Which of the three, in words |
| `ksef_status` | whatever KSeF sent | Stored **verbatim** — not translated, not normalised |
| `direction` | `incoming` / `outgoing` | Decided by comparing NIPs |
| `missing_fields` | list | Which fields KSeF and the XML both failed to supply |

### 5.5 Month-level certainty

Every number that reaches a screen carries one of these six labels
(`Poland\Certainty\DataCertainty`), shown in Polish with the English in
brackets:

| Polish label | English | Meaning |
|---|---|---|
| `ZWERYFIKOWANE` | `VERIFIED` | Computed, **and** the inputs were reconciled against an independent source |
| `WYLICZONE` | `CALCULATED` | Computed correctly from the data present. **Says nothing about completeness.** |
| `WYMAGA PRZEGLĄDU` | `REQUIRES REVIEW` | Something arrived or changed after this was produced. A person must look. |
| `ZA MAŁO DANYCH` | `NOT ENOUGH DATA` | A source **you** could supply is missing — upload the statement and it clears |
| `ZABLOKOWANE` | `BLOCKED` | The **system** cannot proceed — unverified rates, a disabled transport. **This is what a missing KSeF connection produces**, and no amount of uploading changes it |
| `BŁĄD OPERACJI` | `FAILED` | Something ran and broke. There is an error to diagnose and a retry that might work |

> 💡 **`BLOCKED` and `NOT ENOUGH DATA` are deliberately different.** Collapsing
> them would send you hunting for a missing document when the real problem is a
> disabled connection — or the reverse. Same for `BLOCKED` versus `FAILED`:
> "never attempted" is not "attempted and broke".

> 💡 A clean month with no bank statement is `CALCULATED`, not `VERIFIED`.
> Getting the arithmetic right is not the same as having checked it.

---

## 6. Your first test invoice

> ⚠️ **NOT IN THIS VERSION — the Outgoing invoices page.** There is no outgoing
> invoices page, no invoice creation form, no "Submit to KSeF" button, no
> submission status column and no submission history. You cannot create,
> prepare, submit or send an invoice from this application, in TEST or in
> production. Nothing in this repository has ever sent a byte to KSeF.
>
> Three independent mechanisms make this so, and all three are tested:
> `KsefScope::allowed()` returns `InvoiceRead` only;
> `FilingChannel::automated()` returns `false` for every channel;
> and `IntegrationStatus::governmentSubmission()` is hard-coded to report
> `DISABLED` — *"System niczego nie wysyła do ZUS, urzędu skarbowego ani KSeF.
> Każdy kanał wysyłki zgłasza błąd zamiast wysyłać."*

### 6.1 The seven requested steps, against what exists

| # | Requested step | Status today |
|---|---|---|
| 1 | Configure taxpayer profile | ⚠️ Possible, but only by an administrator writing to `pl_tax_profiles`. No screen. |
| 2 | Authenticate | ⚠️ Possible in principle: a credential row with an encrypted InvoiceRead token. But no transport exists to authenticate *with*. |
| 3 | Test connection | ❌ No test-connection feature anywhere. |
| 4 | Create / prepare an outgoing invoice | ❌ No invoice creation of any kind. |
| 5 | Submit only in TEST | ❌ No submission of any kind, in any environment. |
| 6 | Check the resulting status | ❌ Nothing to check — nothing was submitted. |
| 7 | Verify what happened | ✅ Partially: the audit trail below records everything that *does* happen. |

### 6.2 What a first KSeF exercise actually looks like here

Once a transport exists, the honest first exercise is **a download, not an
upload** — and `docs/AUTOMATION.md` already prescribes it:

1. Point the configuration at the **TEST** environment.
2. Generate an **InvoiceRead** token in KSeF test.
3. Run **one sync over a single closed month**.
4. **Compare what arrived against what the taxpayer knows they bought.** This is
   the actual test. Not "did it return 200", but "are these the right invoices".
5. Look at every invoice flagged `needs_review` and understand why.
6. Only then consider a wider window.

### 6.3 What you *can* do today, and it matters

Do not wait for KSeF to start using the system. The dashboard at `/poland` works
now:

**To record a month's sales.** On `/poland`, find the card headed
***"Wprowadź dane"*** ("Enter data"). The sales form has exactly three fields:

| Field label on screen | English | What to enter |
|---|---|---|
| *Miesiąc* | Month | The month, as `YYYY-MM`. Pre-filled with the month you are viewing. **Required.** |
| *Sprzedaż brutto z kasy fiskalnej* | Gross sales from the cash register | The **gross** total, e.g. `48 500,00`. **Required.** |
| *Przyczyna korekty (jeśli miesiąc już zapisany)* | Reason for correction (if the month is already recorded) | Leave empty the first time. Required only when you are correcting a month you already saved. |

Press ***Zapisz sprzedaż*** ("Save sales"). On success:
**"Zapisano sprzedaż za 2026-08."**

> 💡 **The VAT designation is not on the form.** It is derived from your
> profile — `0.23` if you settle VAT, `zw` if you are exempt — and applied
> automatically. `DashboardController::storeSales()` also accepts
> `designation`, `register_id` and `report_number`, but **the dashboard does not
> render inputs for them**, so from the browser you cannot set them. Recorded in
> `docs/OPEN_ITEMS.md`.

**To record a month's costs — this is your KSeF stand-in.** The costs form is
**collapsed by default**. Click the line reading ***"Koszty i VAT naliczony
(nieobowiązkowe — ale bez nich wynik jest górną granicą)"*** — "Costs and input
VAT (optional — but without them the result is an upper bound)" — to open it.

| Field label on screen | English | What to enter |
|---|---|---|
| *Miesiąc* | Month | `YYYY-MM`. **Required.** |
| *Koszty netto* | Net costs | The month's net costs, e.g. `5 000,00`. **Required.** |
| *VAT naliczony* | Input VAT | The deductible VAT, e.g. `1 150,00`. Optional; treated as `0` if empty. |
| *Liczba dokumentów* | Number of documents | How many invoices those figures represent. Defaults to `0`. |

Press ***Zapisz koszty*** ("Save costs"). On success:
**"Zapisano koszty za 2026-08."**

> 💡 Read that summary line literally: **without costs, your result is an upper
> bound, not your tax.** The controller also accepts a `note` field; the form
> does not render one.

> 💡 **The document count matters.** Recording "12 documents" rather than just a
> figure is what later lets somebody ask "KSeF found 9 — where are the other 3?"
> A total with no count cannot be reconciled with anything.

> 🔴 **Absence is not zero.** No costs recorded ≠ no costs incurred. If you did
> not enter your costs, the system does not know about them, and your tax will
> come out too high. Never leave the costs form blank for a month that had costs.

### 6.4 Verifying what happened — the audit trail

Every KSeF action writes to `pl_audit_events`, which **refuses updates and
deletes at the model level**. It is append-only by construction.

| Event | Written when |
|---|---|
| `ksef.synced` | Every sync run — with the full result: imported, duplicates, failures, affected periods, whether it completed, and why it stopped. Marked `ok` when clean, `partial` when not. |
| `ksef.invoice_imported` | Each invoice stored — KSeF number, direction, gross, whether it needs review |
| `report.requires_review` | A month whose report already existed received new KSeF invoices |

> 🔒 **A generated report is never silently rewritten.** If an invoice arrives
> for a month you already settled, the report stays exactly as it was and the
> month is flagged **REQUIRES REVIEW** — *"Nowe faktury z KSeF po wygenerowaniu
> raportu."* A person decides whether to regenerate. Quietly changing a number
> somebody has already acted on is the failure this prevents.

---

## 7. TEST → PRODUCTION

### 7.1 When you should stay in TEST

**Right now: always.** Nobody can move this deployment to production KSeF,
because there is no HTTP transport to move. `KSEF_TRANSPORT=disabled` is the
shipped setting and `UnconfiguredKsefClient` is bound.

Beyond that, stay in TEST while **any** of these is true:

- The transport has not been written against the **current** official API and
  verified at source.
- You have not run a full sync in TEST over a month you can check by hand.
- Your taxpayer profile has not been reviewed field by field.
- Your ryczałt rate has not been confirmed by your accountant.
- 🔴 **The rate tables have not been verified.** Every rate version in this
  system is currently marked `secondary` — taken from competent Polish
  accounting publications and arithmetically cross-checked, but **not confirmed
  against the issuing authority's own publication**. The system tells you so
  (`Stawki podatkowe — NOT VERIFIED`) and refuses to hide it. This is a P0 item
  in `docs/OPEN_ITEMS.md` and it blocks real filing regardless of KSeF.

### 7.2 What must be verified before production

**Technical:**

1. `KsefClient` implemented against the **current** official API — endpoints,
   authentication and schema versions verified against the Ministry's own
   current publication, never from remembered documentation.
2. Tested against the KSeF **test** environment first.
3. `KSEF_TRANSPORT` set to a real transport. Production plus `fake` throws.
4. `KSEF_BASE_URL` correct for production.
5. A backup taken and a **restore drilled** — `bin/data-safety-drill.sh` has
   never restored anything on a real database. Also a P0 item.

**Data:**

6. NIP correct, digit for digit.
7. Business name matching CEIDG.
8. PIT regime and, if ryczałt, the rate — confirmed against your PKD activity.
9. VAT status and settlement frequency.
10. ZUS scheme, sickness insurance, accident rate.
11. Business start month **and day**.
12. Rate tables verified — `php artisan poland:rate-provenance --todo` lists
    exactly what is outstanding, with the official URL for each figure.
    Set `POLAND_REQUIRE_OFFICIAL_RATES=true` so the engine throws rather than
    settling on unverified rates.

### 7.3 Authentication differences between TEST and PRODUCTION

- **The token is different.** A test token is not a production token. They are
  generated separately, in separate systems.
- **The environment is stored per credential.** `pl_ksef_credentials` is unique
  on `(tax_profile_id, environment)`, so a production credential is a *separate
  row*, not an edited one. A token issued for one environment can never be sent
  to another.
- **The base URL is different**, and it is configuration precisely because the
  Ministry has moved these addresses before.
- **Production authentication requires your real qualified signature or trusted
  profile.** A test-environment identity does not carry over.
- **The scope requirement does not change: `InvoiceRead` only, both sides.**

### 7.4 🔴 What production actually means

Even though this application only *reads*:

- **The invoices you download become the basis of a real tax settlement.** If
  the sync is incomplete and you do not notice, your costs are understated and
  you pay too much. If a correction invoice is misfiled, you pay the wrong
  amount.
- **A calculation is not a filing.** Nothing this system produces is a submitted
  return. `filed_at` is set by a successful submission that returned a
  reference, and by nothing else — and no channel can submit. Every report
  carries a disclaimer, and it is never removed.
- **You still file yourself**, through e-Deklaracje / your bank / your
  accountant, and record the reference here afterwards.
- **The audit trail is permanent.** Append-only, and it names the actor.

### 7.5 Verify your data before enabling production

Print the report for one closed month and go down it with your accountant, line
by line, before pointing anything at production. If a figure cannot be
re-derived from its stated source, rate-table version, period, formula and legal
basis, it is a figure you are taking on faith — and tax liabilities are a bad
place for faith.

---

## 8. Troubleshooting

Format: **Problem → likely reason → what to check → what to do next.**

### 8.1 Configuration

| Problem | Likely reason | Check | Do next |
|---|---|---|---|
| I cannot find a KSeF page / an Admin menu | It does not exist. There are nine routes and none is a KSeF route. | §3.3 | Use the **Stan integracji** card on `/poland` for status. Everything else is administrator work. |
| The KSeF row says `NOT CONNECTED` and I cannot fix it | The HTTP transport is not implemented in this deployment | The `→` next step in the *Co to znaczy* column | Nothing from the interface. Enter purchase invoices via **Koszty**. |
| The whole *Stan integracji* card is missing | The dashboard only renders it when the integration list is non-empty | You are on `/poland` and logged in | Contact the administrator |
| Where do I change `KSEF_ENVIRONMENT`? | It is a server `.env` setting | — | Administrator only. Never edit it yourself, and never post its contents anywhere. |
| The application refuses to start after a KSeF change | Production was paired with the simulated transport | `KSEF_ENVIRONMENT` vs `KSEF_TRANSPORT` | Set a real transport, or move back to `test`. The refusal is deliberate. |

### 8.2 Taxpayer profile

| Problem | Likely reason | Check | Do next |
|---|---|---|---|
| **"Najpierw skonfiguruj profil podatnika."** | No row in `pl_tax_profiles` | Whether any profile exists | Administrator must create it. There is no form. |
| Wrong business shown on the dashboard | The dashboard picks the **first** profile by id | Add `?profile=<id>` to the URL | Use the explicit parameter |
| The tax figure looks far too high or too low | Wrong regime, wrong ryczałt rate, or wrong VAT status | Regime, rate, `vat_status` | 🔴 Stop and check with your accountant. A wrong ryczałt rate changes the tax by multiples, and a VAT payer whose status says "exempt" has their ryczałt base overstated by 23%. |
| My first month looks too expensive | Social contributions were not prorated | `business_started_on_day` | An incomplete first month prorates social contributions by days — but the health contribution is **indivisible** and is paid whole. That part is correct. |
| A month with no sales shows nothing | *"This month has no cash-register report yet"* — the normal state before entry | Whether you recorded sales | Record them. **No month recorded ≠ a month of zero sales.** |

### 8.3 Authentication

| Problem | Likely reason | Check | Do next |
|---|---|---|---|
| *"Nie zapisano tokenu KSeF."* | No token stored | `token_encrypted` | Generate an **InvoiceRead** token in KSeF; administrator stores it |
| *"Token KSeF wygasł …"* | Past `token_valid_until` | The date in the message | Generate a fresh token. **Revoke the old one in KSeF.** |
| *"Zakres «InvoiceWrite» nie jest dozwolony …"* | Token scoped too widely | The `scope` column | Generate a token with **InvoiceRead only**. Do not try to work around it — it is refused by design at two independent points. |
| *"Integracja KSeF jest wyłączona dla tego podatnika."* | `enabled = false`, the shipped default | The `enabled` column | Turn it on deliberately, after everything else checks out |
| I want to give support my token to debug | 🔴 **No.** | — | Nobody legitimate will ask. See §9. |

### 8.4 API connectivity

| Problem | Likely reason | Check | Do next |
|---|---|---|---|
| *"Synchronizacja z KSeF niedostępna: …"* | Transport-level failure — network, timeout, HTTP error, auth rejection | The cause in the message | Retry later. Note carefully: **this is not "no invoices".** Do not settle the month on it. |
| *"Klient KSeF nie jest skonfigurowany …"* | `UnconfiguredKsefClient` is bound — no transport exists | Deployment state | Current known state. Administrator task. |
| Sync says **PRZERWANA** (interrupted) | Network dropped, or the per-run cap was hit | The `Wznowienie od` date in the summary | **Nothing was skipped.** Run it again; it resumes from the last confirmed point. The bookmark deliberately does not advance on a failed run. |
| Same invoices keep appearing as duplicates | Expected after an interrupted run | The `duplikaty` count | Harmless. A unique index on `(tax_profile_id, ksef_number)` makes a double import impossible — the database enforces it, not application logic, because application logic is exactly what fails during a retry after a timeout. |
| The month is `BLOCKED` | KSeF was unavailable during month close | The caveat text | *"Sprawdź konfigurację i token KSeF, potem uruchom synchronizację ponownie."* Then regenerate the report. |

### 8.5 Invoice submission

| Problem | Likely reason | Check | Do next |
|---|---|---|---|
| I cannot find where to send an invoice to KSeF | The feature does not exist and is refused by design | §6 | Issue and send invoices through your existing invoicing tool. This system will never do it. |
| *"Zakres KSeF «InvoiceWrite» nie jest dozwolony …"* | Something tried to obtain write permission | — | Not a fault to work around. It requires a deliberate code change with a stated reason. |
| **Wysyłka do organów — DISABLED** | Correct and permanent in this version | — | File yourself and record the reference. `FilingChannel::automated()` is `false` for every channel and a test keeps it that way. |

### 8.6 Common data-entry mistakes

| Mistake | Why it hurts | How you notice |
|---|---|---|
| Entering **net** into the gross sales field | Understates revenue and every downstream figure | Compare against the cash register's own total |
| VAT payer whose profile says "exempt" | Taxing gross takings **overstates a ryczałt base by 23%** | The report shows the designation used |
| Leaving costs blank | **Absence is not zero.** Your tax comes out too high. | The document count is 0 for a month that clearly had purchases |
| NIP typed with dashes / `PL` prefix inconsistently | Direction detection compares digits, so it survives — but your documents will not match | Compare against your CEIDG entry |
| `business_started_on_day` left at 1 | An incomplete first month is not prorated | The first month looks too expensive |
| Editing an already-issued document | 🔴 An issued document is never silently rewritten | The system creates a **correction** that supersedes it and requires a reason; the superseded row stays |
| Recording a figure with no document count | Nothing can ever be reconciled against it | Nobody can answer "KSeF found 9, where are the other 3?" |
| Assuming a `CALCULATED` month is complete | It only means the arithmetic is right | Read the certainty label and the caveats, every time |

---

## 9. Security — read this twice

### 9.1 The absolute rules

🔴 **A KSeF token is a bearer credential.** Whoever holds it can act as you in
KSeF for as long as it is valid. Treat it exactly as you treat the PIN of your
qualified signature.

1. **Never publish KSeF credentials.** Not on a forum, not in a GitHub issue,
   not in a public bug report, not in a Slack channel, not in a Google Doc that
   "only the team" can see.
2. **Never paste a token into a support chat.** Including a chat with an AI
   assistant. Including this one. Nobody legitimate will ever ask you for it —
   if someone does, that is the whole answer.
3. **Never commit credentials to Git.** `.gitignore` excludes `.env` and every
   `.env.*` except `.env.example`; `.env.example` ships `KSEF_BASE_URL`,
   `KSEF_NIP` and `KSEF_TOKEN` empty under the comment *"Never log these
   values"*; and a test scans the repository to assert no credential value was
   ever committed. Do not be the exception. And
   remember: a secret committed and then deleted **is still in the history**.
4. **Never put credentials in a screenshot.** This is the one people forget.
   Before you screenshot a terminal, a config file or a database row, look at
   every character in the frame. A token in a support screenshot is a token in
   whatever ticketing system that screenshot lands in, forever.
5. **Never email or message a token**, even internally, even "just this once".
6. **Never store a token in a spreadsheet, a notes app, or a browser
   auto-fill field.**
7. **Never reuse a production token in a test environment**, or the reverse. The
   system stores them as separate rows precisely so you never have to.

### 9.2 Use TEST before PRODUCTION

Always. TEST exists so you can be wrong without consequence. There is no prize
for skipping it and no way to undo a production mistake that has entered your
tax records.

### 9.3 If a credential is exposed

Act on the assumption it is compromised. "Probably nobody saw it" is not a
security control.

1. **Revoke the token in KSeF immediately**, following the Ministry's current
   procedure. Revocation is the only action that actually stops it — deleting the
   message you pasted it into does not.
2. **Generate a replacement**, InvoiceRead only.
3. **Have the administrator update the stored credential.** The old value must
   be replaced, not left in a "backup" row.
4. **Check `pl_audit_events`** for activity you do not recognise. It is
   append-only, so it cannot have been tidied up.
5. **If it was committed to Git**, rotating the token is mandatory — cleaning
   the history is optional and secondary. Rotate first.
6. **Tell your accountant** if it was a production credential.

### 9.4 What the application does to protect you

You do not have to take this on trust; each item is pinned by a test in
`tests/Unit/CredentialSecurityTest.php`.

| Protection | Mechanism |
|---|---|
| Encrypted at rest | Eloquent `encrypted` cast on `token_encrypted` |
| Never in JSON or array output | `$hidden`, verified by test |
| Never in debug output | `__debugInfo()` returns `[REDACTED]`, verified by test |
| Never in `print_r`, `var_dump`, or a serialised form | Verified by test |
| One read path only | `revealToken()` — greppable, and it refuses a disallowed scope |
| Session tokens redacted | `KsefSession::__toString()` → `token=[REDACTED]`; `reveal()` at one call site |
| Never in an exception message | A test scans the KSeF layer's source for token interpolation |
| Never committed | A test scans the repository |
| Write permissions unobtainable | `KsefScope::allowed()` returns `InvoiceRead`; a test asserts `InvoiceWrite` cannot be obtained at all |
| Your KSeF password never stored | Only a revocable application token is used, by design |

### 9.5 One more thing

The accounting application shares **nothing** with the SignalMesh trading
platform — no database, no session, no credential, no queue. This is enforced
mechanically by `bin/check-isolation.sh`, which runs before every deploy. Your
KSeF token cannot reach the trading system because there is no path between
them.

---

## 10. Quick-start checklist

Print this page. Tick nothing you have not actually verified.

### Today — what you can genuinely do

```
☐  Taxpayer information gathered
      ☐ Business name exactly as in CEIDG
      ☐ NIP verified digit by digit against CEIDG
      ☐ REGON (optional)
      ☐ Business start month AND day of month
☐  Tax setup confirmed WITH YOUR ACCOUNTANT
      ☐ PIT regime (ryczałt / scale / flat)
      ☐ Ryczałt rate for your actual PKD activity — not from an example
      ☐ VAT status and settlement frequency
      ☐ ZUS scheme, sickness insurance, accident rate
      ☐ Mały ZUS Plus base / previous year's revenue, if applicable
☐  Taxpayer profile created in pl_tax_profiles by the administrator
☐  Dashboard opens at /poland and shows your business
☐  "Stan integracji" card located and read, all five rows
☐  Sales recorded for the current month (gross, correct VAT designation)
☐  Costs recorded for the current month (net, input VAT, document count)
☐  Report generated and read with your accountant
☐  You have read §9 and understand you never share a token with anyone
```

### The KSeF sequence — what it will look like when it exists

Every line below is blocked today by the missing HTTP transport.

```
☐  Taxpayer information ready                    ← possible today
☐  NIP verified                                  ← possible today
☐  Seller details entered                        ← partly: no address fields exist
☐  TEST environment selected                     ← KSEF_ENVIRONMENT=test (shipped default)
☐  Authentication configured                     ← BLOCKED: no transport, no entry screen
      ☐ InvoiceRead-only token generated in KSeF
      ☐ Token stored encrypted by the administrator
      ☐ Expiry recorded in token_valid_until
      ☐ enabled set to true, deliberately
☐  Connection test successful                    ← BLOCKED: no test-connection feature exists
☐  Default invoice details checked               ← DOES NOT APPLY: no invoice defaults exist
☐  TEST invoice verified                         ← DOES NOT APPLY: this system never issues
                                                    or submits invoices, by design
☐  Production readiness confirmed                ← BLOCKED
      ☐ Transport implemented against the CURRENT official API
      ☐ Full sync run in TEST over one closed month
      ☐ Downloaded invoices compared against what you know you bought
      ☐ Every needs_review invoice understood
      ☐ Rate tables VERIFIED against official sources  ← P0, blocks real filing
      ☐ Backup taken and restore drilled on a real database  ← P0
☐  Production authentication configured          ← BLOCKED
      ☐ Separate production credential row, separate token
      ☐ Production base URL
      ☐ KSEF_TRANSPORT set to a real transport (never "fake")
☐  Final production test completed                ← BLOCKED
```

### The three sentences to remember

1. **`NOT CONNECTED` is about the system, never about your invoices.** It never
   means "you had no purchases".
2. **This application reads from KSeF. It never writes to it.** It cannot issue,
   submit or file anything, and that is deliberate.
3. **Never give your token to anyone.** Not support, not a developer, not a
   chat, not a screenshot.

---

## Appendix A — what was verified, and where

Every claim in this guide was checked against the source on **2026-09-09**.
Paths are relative to the repository root.

### Exists and works as documented

| Documented item | Where |
|---|---|
| The nine routes, exactly as listed | `modules/poland/routes/web.php` |
| Dashboard screen and the *Stan integracji* card | `modules/poland/resources/views/dashboard.blade.php` (lines 231–256) |
| The five integration rows and every status string | `modules/poland/src/Certainty/IntegrationStatus.php` |
| `NOT CONNECTED` / `TEST TRANSPORT` / `CONNECTED` / `UNAVAILABLE` | `IntegrationStatus::ksef()` |
| Environments `test` / `demo` / `production` and their labels | `modules/poland/src/Ksef/KsefEnvironment.php` |
| Production refusing a simulated transport | `modules/poland/src/Ksef/TransportGate.php::assertUsable()` |
| Failure never phrased as an empty result | `TransportGate::reportFailure()` |
| `InvoiceRead` only; write scopes named so they can be refused | `modules/poland/src/Ksef/KsefScope.php` |
| Every credential column | `modules/poland/database/migrations/2026_09_07_000800_create_poland_ksef_tables.php` |
| Token encryption, hiding, redaction, single read path | `modules/poland/src/Laravel/Models/KsefCredentialModel.php` |
| Every `unusableReason()` message quoted in §5.2 | same file |
| Every `unavailableReason()` message quoted in §5.2 | `modules/poland/src/Laravel/Support/KsefIngestService.php` |
| Refusal messages from the unconfigured client | `modules/poland/src/Ksef/UnconfiguredKsefClient.php` |
| Sync bookmark rules (a failed run never advances the mark) | `modules/poland/src/Ksef/SyncCursor.php` |
| Sync summary wording, clean vs partial | `modules/poland/src/Ksef/KsefSyncResult.php` |
| `needs_review` triggers and their three reasons | `KsefIngestService::import()` |
| Direction decided by comparing NIP digits | `modules/poland/src/Ksef/KsefInvoiceMetadata.php` |
| Immutable XML and KSeF number | `modules/poland/src/Laravel/Models/KsefDocumentModel.php` |
| Duplicate prevention by unique index | the migration above |
| Audit events `ksef.synced`, `ksef.invoice_imported` | `modules/poland/src/Laravel/Support/AuditRecorder.php` |
| A settled month flagged REQUIRES REVIEW, never rewritten | `KsefIngestService::flagAffectedReports()` |
| `BLOCKED` certainty and its remedy text | `modules/poland/src/Certainty/Caveat.php::ksefUnavailable()` |
| Every filing channel non-automated | `modules/poland/src/Reporting/FilingChannel.php::automated()` |
| Government submission always `DISABLED` | `IntegrationStatus::governmentSubmission()` |
| Every configuration key and default in §4 | `modules/poland/config/poland.php`, `.env.example` |
| Every taxpayer-profile field in §4 Step 2 | `modules/poland/database/migrations/2026_09_07_000100_create_poland_tax_profiles_table.php` |
| Sales/costs forms, their validation and their messages | `modules/poland/src/Laravel/Http/Controllers/DashboardController.php` |
| The exact fields the two forms render | `dashboard.blade.php` lines 260–305 |
| The derived `0.23` / `zw` VAT designation | `DashboardController::storeSales()` |
| Audit events append-only at the model level | `modules/poland/src/Laravel/Models/AuditEventModel.php` |
| Security guarantees in §9.4 | `modules/poland/tests/Unit/CredentialSecurityTest.php` (10 properties) |
| Status guarantees in §5.1 | `modules/poland/tests/Unit/IntegrationStatusTest.php` |
| Bookmark guarantees in §5 / §8.4 | `modules/poland/tests/Unit/SyncSafetyTest.php` |
| The six certainty labels and their Polish text | `modules/poland/src/Certainty/DataCertainty.php` |

### Does not exist — searched for and not found

| Requested item | Verification |
|---|---|
| `Admin` menu, `Tax & Compliance` section, `KSeF` page | No such route in `routes/web.php`; no such controller; only three Blade templates exist (`dashboard`, `report`, `history`) |
| Five-step configuration wizard | No wizard route, controller, view or session state anywhere |
| "Test connection" button | No route, no controller method, no view element |
| Outgoing invoices page | No route, no controller, no view. `direction = outgoing` only labels *downloaded* invoices |
| Invoice creation / preparation / submission | Refused at three independent points (§6) |
| Invoice defaults (payment terms, numbering, bank account, default VAT rate) | No such columns in any migration |
| Company address fields | `pl_tax_profiles` has no address columns |
| Taxpayer profile create/edit form | The dashboard reads profiles; nothing writes them |
| KSeF token entry form | No such field or screen; credentials come from the database and `.env` |
| A way to trigger a sync from the interface | `MonthCloseService` is registered but called by no route and no command; the three Artisan commands are `poland:report`, `poland:verify-rates`, `poland:rate-provenance` |
| KSeF HTTP transport | `UnconfiguredKsefClient` is bound in `PolandServiceProvider`; `KSEF_TRANSPORT=disabled` |
| A status literally named `NOT CONFIGURED` | The panel uses `NOT CONNECTED`; *"nie jest skonfigurowana"* appears as an error message |
| Form inputs for `designation`, `register_id`, `report_number`, `note` | Accepted by `DashboardController` validation; no `<input>` is rendered for any of them in `dashboard.blade.php` |

Every gap above is recorded in `docs/OPEN_ITEMS.md`.

---

## Appendix B — glossary

| Term | Plain meaning |
|---|---|
| **KSeF** | Krajowy System e-Faktur — the Ministry of Finance's national e-invoicing system |
| **KSeF number** | The permanent identifier KSeF assigns to an invoice. Globally unique; this system's identity for that invoice, and immutable once stored |
| **NIP** | Your 10-digit tax identification number |
| **REGON** | Statistical business number from GUS |
| **CEIDG** | The register of sole traders |
| **JDG** | Jednoosobowa działalność gospodarcza — sole proprietorship |
| **Token** | A password-like string that proves this application may read your KSeF inbox. Revocable on its own |
| **Scope / zakres** | What a token is allowed to do. Here: `InvoiceRead` only |
| **InvoiceRead** | Permission to read invoices. The only scope this application accepts |
| **InvoiceWrite** | Permission to issue invoices in your name. **Refused by this application** |
| **Transport** | The part of the software that actually speaks HTTP to KSeF. Not implemented in this deployment |
| **Sync / synchronizacja** | One run that downloads invoices for a date range |
| **Cursor / bookmark** | How far a sync got, so an interrupted run resumes instead of restarting or skipping |
| **FA** | The Ministry's XML schema for a structured invoice |
| **KOR** | A correction invoice. It supersedes an earlier one and always needs review |
| **needs_review** | This invoice needs a person: fields missing, totals disagreeing, or it is a correction |
| **Ryczałt** | A tax regime taxing **revenue**, not income |
| **Skala / podatek liniowy** | Regimes taxing **income** (revenue minus costs) |
| **ZUS** | Social insurance institution |
| **JPK_V7** | The VAT return/register file sent to the Ministry. Not produced by this system |
| **e-Deklaracje** | The Ministry's own filing portal, where you actually file |
| **Append-only** | Records can be added but never changed or deleted |
| **Bearer credential** | Anything whose mere possession grants access. Your KSeF token is one |

---

*Document version 1.0 — 2026-09-09. Verified against the code as of that date.
If the application changes, this guide is wrong until somebody re-verifies it
against Appendix A. Nothing in this document changed any code.*
