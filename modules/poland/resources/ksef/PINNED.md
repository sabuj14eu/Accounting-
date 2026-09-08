# Pinned official KSeF 2.0 specification

Everything the integration is written against is copied here verbatim from the
Ministry of Finance's public repositories, so a build never depends on the
network and a reviewer can diff what the code targets against what the
Ministry publishes. Nothing in this directory is hand-edited. `SHA256SUMS`
lists the checksums of every pinned file.

| Item | Value |
|---|---|
| Source repository | https://github.com/CIRFMF/ksef-docs (branch `main`) |
| Source commit | `93b843d5def041f69fe2a26d0d90a53e9fa9987a` (2026-08-26) |
| Reference client consulted | https://github.com/CIRFMF/ksef-client-java @ `4e9b10a7c1ef1d1528bf2c1e82de1b4c9677e256` (for the sample FA(3) invoice only; no code copied) |
| API version | **2.7.1** (build `2.7.1-te`), OpenAPI document `openapi/open-api-2.7.1-te.json` |
| API path prefix | `/v2` (OpenAPI `servers[0].url` = `https://api-test.ksef.mf.gov.pl/v2`) |
| Invoice schema | **FA(3)**, `kodSystemowy="FA (3)"`, `wersjaSchemy="1-0E"`, `WariantFormularza=3` |
| FA(3) namespace | `http://crd.gov.pl/wzor/2025/06/25/13775/` |
| FA(3) XSD | `xsd/FA/schemat_FA(3)_v1-0E.xsd` + `xsd/FA/bazowe/{StrukturyDanych,ElementarneTypyDanych,KodyKrajow}_v10-0E.xsd` |
| UPO schema | `xsd/upo/upo-v4-3.xsd`, namespace `http://upo.schematy.mf.gov.pl/KSeF/v4-3` |
| Auth XSD (XAdES path, not used yet) | `xsd/auth/schemat_auth_v2-1.xsd` |
| Pinned on | 2026-09-08 |

## Environments — explicitly pinned official endpoints

Every API base URL below is taken verbatim from official Ministry material
copied into `environments/` (checksummed in `SHA256SUMS`). None is inferred from a pattern.

| Environment | API base used by this application | Official source of the host | Official source of the `/v2` path |
|---|---|---|---|
| TEST | `https://api-test.ksef.mf.gov.pl/v2` | `openapi/open-api-2.7.1-te.json` → `servers[0].url`; `environments/ksef-client-java/application.yaml` → `base-uri` | `servers[0].url` and `suffix-uri: "v2"` in the same files |
| DEMO | `https://api-demo.ksef.mf.gov.pl/v2` | `environments/ksef-client-java/application-demo.yaml` → `base-uri` (official reference client, commit `4e9b10a7`) | `suffix-uri: "v2"` (`application.yaml`, shared by all profiles) |
| PRODUCTION | `https://api.ksef.mf.gov.pl/v2` | `environments/ksef-client-java/application-prod.yaml` → `base-uri` | same |

Documentation and taxpayer-application hosts per environment come from
`environments/srodowiska.md` (`ksef-docs`, commit `93b843d5`): TEST
https://api-test.ksef.mf.gov.pl/docs/v2, DEMO https://api-demo.ksef.mf.gov.pl/docs/v2,
PRODUCTION https://api.ksef.mf.gov.pl/docs/v2. The same document forbids
production invoices or real taxpayer data on TEST and DEMO, and notes that TEST
data is shared between integrators (use random test NIPs only).

`KsefEnvironmentPinTest` asserts that `KsefEnvironment::pinnedApiBaseUrl()`,
the defaults in `config/poland.php` and this table all equal the values in the
pinned official files, so a re-pin that moves a host fails the suite instead
of silently changing where invoices go. The production URL cannot be
overridden by an environment variable (`KsefEndpoints`). "Confirmed live" is
still a separate fact: Gate 3 of `docs/KSEF_PRODUCTION_GATE.md` records the
first real response from each host.

## Endpoints this application uses

| Purpose | Method and path |
|---|---|
| Public keys for client-side encryption | `GET /security/public-key-certificates` |
| Authentication challenge | `POST /auth/challenge` |
| Authentication with a KSeF token | `POST /auth/ksef-token` |
| Authentication status | `GET /auth/{referenceNumber}` |
| Redeem access + refresh token (once) | `POST /auth/token/redeem` |
| Refresh access token | `POST /auth/token/refresh` |
| Revoke current authentication session | `DELETE /auth/sessions/current` |
| Open interactive session (FA(3)) | `POST /sessions/online` |
| Send one encrypted invoice | `POST /sessions/online/{referenceNumber}/invoices` |
| Close interactive session | `POST /sessions/online/{referenceNumber}/close` |
| Session status | `GET /sessions/{referenceNumber}` |
| Session invoices (used for safe recovery after a timeout) | `GET /sessions/{referenceNumber}/invoices` |
| One invoice's status in a session | `GET /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}` |
| Invoice UPO | `GET /sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}/upo` |
| Session UPO | `GET /sessions/{referenceNumber}/upo/{upoReferenceNumber}` |
| Incoming/outgoing invoice metadata, paged | `POST /invoices/query/metadata?sortOrder=Asc&pageOffset=N&pageSize=M` |
| One invoice's XML | `GET /invoices/ksef/{ksefNumber}` |
| Current API rate limits (health page) | `GET /rate-limits` |

Not used, deliberately: XAdES authentication (needs a qualified
certificate), batch sessions, invoice export packages, permissions management,
certificate enrolment, test-data endpoints.

## Cryptography required by the contract

- KSeF token authentication: `{token}|{timestampMs}` encrypted with the
  Ministry's public key, **RSA-OAEP with SHA-256 (MGF1-SHA-256)**, Base64.
- Interactive session: invoice XML encrypted **AES-256-CBC, PKCS#7**, 32-byte
  key and 16-byte IV; the key wrapped with **RSAES-OAEP SHA-256/MGF1-SHA-256**
  using the certificate whose `usage` contains `SymmetricKeyEncryption`.
- The key used is identified to the API by `publicKeyId`; error code 21470
  means the key was rotated and the list must be fetched again.

## How to re-pin

    git clone --depth 1 https://github.com/CIRFMF/ksef-docs
    cp ksef-docs/open-api.json  openapi/open-api-<version>-te.json
    cp 'ksef-docs/faktury/schemy/FA/schemat_FA(3)_v1-0E.xsd' xsd/FA/
    cp ksef-docs/faktury/schemy/FA/bazowe/*.xsd xsd/FA/bazowe/
    cp ksef-docs/faktury/upo/schemy/upo-v4-3.xsd xsd/upo/
    sha256sum ... > SHA256SUMS   # and update this table, then run the suite

A change of FA version or namespace is a deliberate release, never a silent
re-pin: `Poland\Ksef\Fa3\Fa3Schema` asserts the namespace and the
`kodSystemowy`/`wersjaSchemy` values above, and the tests fail if the
pinned XSD stops matching them.
