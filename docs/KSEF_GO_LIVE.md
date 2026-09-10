# KSeF go-live — runbook and results record

Stage C built the real transport. This file is how it is put into service,
in the order the production audit (§1) requires, and where the results of
each live run are recorded. **Nothing in this file is done yet.** The
transport ships `KSEF_TRANSPORT=disabled`; the results table below is empty
until somebody with a token runs the checklist and writes down what happened.

The rule that governs every step: **a failed or missing connection is never
"no invoices".** Every refusal in the pipeline says so in its own words.

## What was built (commit on `claude/determined-newton-e4kklt`, 2026-09-11)

| Piece | Where | Written against |
|---|---|---|
| HTTP client | `Poland\Ksef\HttpKsefClient` | KSeF API 2.0, OpenAPI **2.7.1**, `CIRFMF/ksef-docs` commit `93b843d` (2026-08-26) |
| Token auth | `Poland\Ksef\Auth\TokenEncryptor` | `POST /auth/challenge` → RSA-OAEP/SHA-256 of `token|timestampMs` with the `KsefTokenEncryption` key → `POST /auth/ksef-token` → poll `GET /auth/{ref}` → `POST /auth/token/redeem` |
| Retrieval | `queryInvoices` / `fetchInvoiceXml` | `POST /invoices/query/metadata` as **Subject2** (the shop as buyer), `dateType=PermanentStorage`, `sortOrder=Asc`, paging by `pageOffset`, `isTruncated` narrowing; `GET /invoices/ksef/{ksefNumber}` |
| Number check | `Poland\Ksef\KsefNumber` | 35-char format + CRC-8 (poly 0x07), verified against numbers quoted in the Ministry's docs |
| Transport | `Poland\Ksef\Http\CurlTransport` | https only, TLS verified, **no redirects followed**, explicit timeouts |
| Retries | in the client | 429 honours `Retry-After` (capped 60 s); 5xx and network errors back off 2/4/8 s up to `KSEF_MAX_RETRIES`; **authentication initiation is never retried** |
| Egress registry | `config/poland.php` → `egress.ksef` | the only place a KSeF hostname exists (test asserts it); disabled by default |
| Client factory | `Poland\Laravel\Support\KsefClientFactory` | the one `revealToken()` call site; refuses a token from a different environment than the one configured |
| Commands | `poland:ksef-token`, `poland:ksef-check`, `poland:ksef-sync` | hidden-prompt token storage · 12-step live checklist · scheduled pull |
| Scheduler | provider, only when the transport is enabled | every `KSEF_SYNC_EVERY_MINUTES` (default 120, floor 15) |

Unit evidence: `HttpKsefClientTest` (27 tests, one group per checklist step
4–12 against recorded 2.7.1 responses), `KsefNumberAndEncryptionTest` (the
OAEP output is decrypted by OpenSSL's own OAEP-SHA-256, not by our code),
`GoLiveChecklistTest`, `EgressPolicyTest`. Module suite: 421 tests.

## 1. Get a TEST token (the owner does this; the application cannot)

The application deliberately holds **InvoiceRead only**, and generating a
token is a `CredentialsManage`-class act, so it never does this itself.

On the TEST environment (`https://api-test.ksef.mf.gov.pl/v2`):

1. **Use a random test NIP, never the real one.** The TEST environment is
   shared between integrators and is not isolated (Ministry's note in
   `srodowiska.md`). Create the test owner with `POST /testdata/person`
   (`nip`, `pesel`, `description`, `isBailiff: false`) or through the
   Ministry's test taxpayer application.
2. **Authenticate once with a signature.** A KSeF token can only be generated
   after an XAdES authentication. On TEST a **self-signed** certificate is
   accepted; the Ministry publishes a test certificate helper in the
   `ksef-docs` repository (`certyfikaty-KSeF.md`). Alternatively log in to
   the test taxpayer application for that NIP.
3. **Generate the token with exactly `["InvoiceRead"]`** (`POST /tokens`,
   or the Tokens screen). A token with `InvoiceWrite` or `CredentialsManage`
   is refused by this application — revoke it and make a new one.
4. Copy the token once. It will be pasted into a hidden prompt on the
   server and never displayed again.

To make steps 6, 7 and 9 of the checklist testable, the test inbox needs
invoices: from a **second** test NIP, issue FA(3) invoices whose `Podmiot2`
is the shop's test NIP (more than ten for step 7 to exercise paging).

## 2. Configure and store, on the server

```
# in /srv/accounting/foundation/.env
KSEF_ENVIRONMENT=test
KSEF_TRANSPORT=real
KSEF_TRANSPORT_ENABLED=true
KSEF_EGRESS_ENABLED=true
KSEF_EGRESS_AUTHORISED_BY="<owner's name>"
KSEF_EGRESS_AUTHORISED_ON=2026-09-XX
```

Then, as the `accounting` user, in `/srv/accounting/foundation`:

```
/usr/bin/php8.5 artisan config:clear
/usr/bin/php8.5 artisan poland:ksef-token --environment=test
```

The command asks for the token in a hidden prompt, encrypts it (Laravel's
`encrypted` cast, `APP_KEY`), stores it under `pl_ksef_credentials` for the
**test** environment only, and writes `ksef.token_stored` to the audit log
with a 12-hex fingerprint — never the token.

The profile's NIP is what the client authenticates as. For the TEST run the
profile must carry the **test** NIP; put the real one back before production.

## 3. Run the checklist

```
/usr/bin/php8.5 artisan poland:ksef-check
```

It prints twelve rows and exits 0 only when all twelve are `PASS`. It imports
nothing. It records the run as `ksef.check_run` in the audit log and stamps
`last_verified_at` on the credential when step 4 passes (or `last_error` when
it does not).

| # | Step | How it is tested live | Can it be PASS live? |
|---|---|---|---|
| 1 | Official specification obtained | pinned in code | yes |
| 2 | API version pinned | `config` = `HttpKsefClient::API_VERSION` | yes |
| 3 | Transport | TLS + `GET /security/public-key-certificates`, no credential sent | yes |
| 4 | Authentication | full token flow, session opened | yes |
| 5 | InvoiceRead authorisation | `invoices/query/metadata` answers 200 (403 = missing permission) | yes |
| 6 | Invoice retrieval | first invoice in the window fetched and parsed | only if the inbox has ≥ 1 invoice, else `NOT TESTED` |
| 7 | Pagination / cursors | all pages at page size 10 walked; numbers unique | only if the inbox has > 10 invoices, else `NOT TESTED` |
| 8 | Retries / timeouts | cannot be induced from outside | **never** — `NOT TESTED` live, `PASS` in unit tests |
| 9 | Duplicate delivery | same window read twice, sets compared | only with ≥ 1 invoice |
| 10 | Malformed responses | cannot be induced from outside | **never** — `NOT TESTED` live, `PASS` in unit tests |
| 11 | Revoked / expired credentials | a deliberately wrong token must be rejected as a credential problem | yes |
| 12 | API version changes | live `open-api.json` version compared with the pin | yes, if the docs endpoint is reachable |

`NOT TESTED` is not `PASS`. Steps 8 and 10 will **always** be `NOT TESTED`
on a live run; the go-live decision accepts their unit-test evidence
explicitly, in writing, in the results record below — or it does not go live.

## 4. Results record (append one block per run; never edit an old one)

```
Run:            <date time UTC>
Environment:    test | demo | production
Operator:       <name>
Commit:         <git rev-parse --short HEAD on the server>
Token:          fingerprint <12 hex from the ksef.token_stored audit row>, scope InvoiceRead, valid until <date|unknown>
Inbox:          <n> invoices in the window <from> → <to>

 #  Step                         Verdict      Observed
 1  Specification                
 2  Version pin                  
 3  Transport                    
 4  Authentication               
 5  InvoiceRead                  
 6  Retrieval                    
 7  Pagination                   
 8  Retries/timeouts             NOT TESTED   accepted on unit evidence: <yes/no, by whom>
 9  Duplicates                   
10  Malformed                    NOT TESTED   accepted on unit evidence: <yes/no, by whom>
11  Revoked/expired              
12  Version drift                

Decision:       <go-live approved for production: yes/no> — <by whom>
```

The command's `--json` output is the raw source; paste the human table here.

## 5. Production — only after section 4 says yes

1. Put the **real NIP** back in the taxpayer profile.
2. Generate a **production** token with `InvoiceRead` only, in the real KSeF
   (login through the taxpayer's trusted profile). Nothing else.
3. Store it: `poland:ksef-token --environment=production --go-live-passed-on=YYYY-MM-DD`.
   Without that date the command refuses a production token.
4. `KSEF_ENVIRONMENT=production` in `.env`; `config:clear`.
5. Install the systemd egress allowlist for the production host only
   (`deploy/systemd/egress-allowlist.conf.example`); `daemon-reload`; restart
   the queue unit.
6. `poland:ksef-check` once more, now against production. It reads; it
   imports nothing.
7. First sync by hand: `poland:ksef-sync --from=<45 days ago>`. Compare what
   arrived in the review inbox with what the owner knows was bought. Only
   then leave the scheduler running.

A test token is never stored for production and a production token never
for test: the factory refuses a credential whose environment differs from
`KSEF_ENVIRONMENT`, and the two rows are distinct in `pl_ksef_credentials`.

## 6. Turning it off

`KSEF_TRANSPORT_ENABLED=false` stops the scheduler and every sync; the
dashboard then says NOT CONNECTED. `poland:ksef-token --remove
--environment=<env>` deletes the stored token (audited). Revoke the token in
KSeF as well — the application cannot do that for you.
