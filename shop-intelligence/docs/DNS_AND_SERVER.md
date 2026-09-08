# shop.signalmesh.dev — DNS and server preparation

**Read the first line twice: this prepares infrastructure. It does not deploy an
application, because there is none** (`SPEC_AUDIT_2026-09-08.md` §1). After
these steps the domain resolves, has a certificate, has its own database, user,
storage root and PHP-FPM pool, and serves one page that says exactly that.

Everything here is separate from `account.signalmesh.dev` and from the trading
platform: different system user, database, database user, socket, server
block, log files, storage root and backup directory. Nothing under
`/srv/accounting` is read or written.

## 1. Cloudflare — zone `signalmesh.dev`

Dashboard: `https://dash.cloudflare.com/<account>/signalmesh.dev` → **DNS → Records**.

| Type | Name | Content | Proxy status | TTL |
|---|---|---|---|---|
| `A` | `shop` | `62.171.164.19` — the Contabo box, the same address `app`, `brain` and `status` resolve to | **DNS only** (grey cloud) until the certificate is issued | Auto |
| `AAAA` | `shop` | the box's IPv6, only if `account` has one | same | Auto |

Get the address from the box rather than from memory:

```bash
curl -4 -s https://ifconfig.me; echo
```

or copy it from the existing `account` record in the same zone. No CNAME, no
wildcard, no record for `www.shop`.

**Order matters.** Create the record as *DNS only* first, run the server script
(it issues the certificate over HTTP-01), and only then decide about the
orange cloud. If the record is proxied before the certificate exists, certbot
may fail; if the zone's **SSL/TLS mode is "Flexible"**, a proxied record plus
the vhost's 80→443 redirect produces a redirect loop. Should you proxy it later,
set SSL/TLS to **Full (strict)** for the zone (or an origin rule for this
host) — the box has a real Let's Encrypt certificate, so strict is correct.

Verified 2026-09-08 from outside: `shop.signalmesh.dev` resolves to
`62.171.164.19`, as do `app`, `brain` and `status`. **`account.signalmesh.dev`
does not resolve at all** — the accounting application has no DNS record in
this zone, so whatever `docs/DEPLOYMENT.md` describes as live cannot be
reached by that name. That is the accounting side's item, recorded here
because it was found here; add an `A` record for `account` the same way.

```bash
dig +short shop.signalmesh.dev A          # 62.171.164.19
dig +short account.signalmesh.dev A       # empty today — see above
```

## 2. Contabo — run once, as root

**The repository is private**, so `git clone https://github.com/...` on the box
asks for a username and a token, and GitHub refuses passwords. The script
never needs GitHub itself: it copies the checkout it is run *from*. So the
only problem is getting the code onto the box once, without a password. Use a
read-only deploy key — a key pair that lives on the box and can only read this
one repository.

```bash
ssh <user>@<contabo-box>
sudo -i

# 1. A key that exists only on this box, only for this repository.
ssh-keygen -t ed25519 -N "" -C "contabo read-only deploy key: Accounting-" -f /root/.ssh/github-accounting
cat /root/.ssh/github-accounting.pub
```

Copy the printed line (one line, starts with `ssh-ed25519`) into GitHub:
**repository `sabuj14eu/Accounting-` → Settings → Deploy keys → Add deploy
key**, title `contabo`, leave *Allow write access* **unticked**, Add key.
Then back on the box:

```bash
# 2. Tell ssh to use that key for github.com, and trust GitHub's host key.
cat >> /root/.ssh/config <<'EOF2'
Host github.com
    IdentityFile /root/.ssh/github-accounting
    IdentitiesOnly yes
EOF2
chmod 600 /root/.ssh/config
ssh-keyscan -t ed25519 github.com >> /root/.ssh/known_hosts 2>/dev/null
ssh -T git@github.com 2>&1 | head -1          # "Hi sabuj14eu/Accounting-! ... does not provide shell access." = key works

# 3. Fetch the branch with the key (no prompt), then run the script FROM that checkout.
git clone --branch claude/regression-map-audit-lis0w8 git@github.com:sabuj14eu/Accounting-.git /root/shop-prep
bash /root/shop-prep/shop-intelligence/bin/prepare-server.sh
```

Paste the two last lines **one at a time**. The earlier failure happened
because the second line was pasted while git was still waiting at its
`Username:` prompt, so the command text became the username.

No deploy key wanted? Copy the checkout from a machine that already has it,
and the script needs nothing else:

```bash
# on your own computer, from a clone of the repository:
git -C /path/to/Accounting- checkout claude/regression-map-audit-lis0w8
scp -r /path/to/Accounting- root@62.171.164.19:/root/shop-prep
# on the box:
bash /root/shop-prep/shop-intelligence/bin/prepare-server.sh
```

The script is idempotent and stops at the first failed check. In order it:

1. installs nginx, MariaDB and PHP 8.5 (adding the ondrej/sury PHP
   repository if the box does not have it — verified 2026-09-08 that this box
   had no PHP at all, so the accounting installer has never run here either);
2. creates the `shop` user, `/srv/shop-intelligence`, `/var/lib/shop-intelligence`
   (750) and `/var/backups/shop-intelligence` (700, root);
3. copies the checkout it was run from into `/srv/shop-intelligence/app` with
   a local `git clone` (no GitHub access needed) and **runs the isolation
   guard; it refuses to continue if the guard fails**;
4. creates database `shop_intelligence` and user `shop_intelligence@127.0.0.1`
   with grants on that schema and its `_drill` restore namespace only, then
   logs in as that user and **proves it sees no other database**;
5. writes `/srv/shop-intelligence/.env` (600) from `.env.example` with the
   generated password — the password exists in that file and in
   `/srv/shop-intelligence/.db-password` (600) and nowhere else;
6. writes the `shop` PHP-FPM pool, socket `/run/php/php8.5-fpm-shop.sock`,
   `open_basedir` limited to its own two directories;
7. writes the nginx server block and the one static page;
8. issues the certificate with certbot and enables the HTTPS redirect.

Then read the summary it prints, and check:

```bash
curl -sI https://shop.signalmesh.dev | head -3           # 200, HSTS header
curl -s  https://shop.signalmesh.dev | grep -c 'nie wdrożono'   # 1
mysql -u shop_intelligence -p"$(cat /srv/shop-intelligence/.db-password)" -h 127.0.0.1 -e 'SHOW DATABASES;'
#   information_schema + shop_intelligence only. Anything else = stop, grants are wrong.
ls -la /run/php/                                          # both sockets, different owners' pools
systemctl status php8.5-fpm nginx mariadb --no-pager | grep -E 'Active|●'
sudo -u accounting true && echo 'accounting untouched'   # the other app's user still exists and was not used
```

And the two things the script must NOT have changed:

```bash
curl -sI https://account.signalmesh.dev | head -1        # still 200
diff <(ls /etc/nginx/sites-enabled) <(echo account.signalmesh.dev; echo shop.signalmesh.dev) || true
```

## 3. What the next deploys look like

There is no deploy ceremony yet because there is nothing to migrate. When the
P0 work exists (schema and migrations under `shop_intelligence`, its own login,
the immutable month close), the ceremony is the accounting side's, copied not
shared: **backup → guard → migrate → restart the `shop` pool → verify in the
logs**, with a `deploy/backup-shop.sh` that restores into
`shop_intelligence_drill` and counts rows before it calls itself a backup.

Until then the only honest status for `shop.signalmesh.dev` is the page it
serves.
