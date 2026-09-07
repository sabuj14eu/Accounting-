# Deployment — account.signalmesh.dev

## Prerequisite that will bite you first

**The Liberu ERP requires PHP 8.5.** This is not a soft requirement from
`composer.json` that `--ignore-platform-reqs` gets you past: upstream uses
`#[\Override]` on class properties, which is PHP 8.5 syntax. On PHP 8.4 the
application fails to **parse**, before any autoloader runs, and the resulting
fatal error mentions an attribute rather than a version:

```
PHP Fatal error: Attribute "Override" cannot target property
  (allowed targets: method) in app/Providers/AuthServiceProvider.php on line 18
```

Verified on 2026-09-07 against upstream commit `3a23437`. `composer install`
itself succeeds on PHP 8.4 with `--ignore-platform-reqs --no-dev`; only running
the application fails. `bin/install-foundation.sh` checks the version up front so
this surfaces as a clear message.

The Poland module itself requires only PHP 8.2, and its CLI and test suite run
anywhere. If the host cannot be moved to 8.5 yet, the tax engine is still fully
usable via `modules/poland/bin/pl-tax` while the ERP waits.

## Requirements

- PHP 8.5 with `mbstring`, `intl`, `dom`, `xml`, `xsl` (KSeF/JPK schema
  validation), `pdo_mysql`, `redis`, `zip`, `gd`
- MySQL 8 or MariaDB 10.6+
- Redis
- Composer 2, Node 20+ (asset build), nginx

## Install

```bash
sudo useradd -r -m -d /srv/accounting accounting
sudo -u accounting git clone <this repo> /srv/accounting/app
cd /srv/accounting/app

bin/install-foundation.sh /srv/accounting/foundation
```

The script clones the pinned Liberu ERP, symlinks `modules/poland` into it as a
Composer path repository, adds the requirement, installs, and copies `.env`.

Then:

```bash
cd /srv/accounting/foundation
$EDITOR .env                      # database, Redis DB indexes, APP_URL
php artisan key:generate
php artisan migrate
php artisan poland:verify-rates
```

## Database and Redis

Create a database and a user that exist **only** for accounting:

```sql
CREATE DATABASE accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'accounting'@'127.0.0.1' IDENTIFIED BY '<generated>';
GRANT ALL PRIVILEGES ON accounting.* TO 'accounting'@'127.0.0.1';
```

`GRANT ... ON accounting.*` and nothing wider. The accounting user must not be
able to see the trading schema even if a query tried.

Redis is shared as a process but not as a keyspace: `REDIS_DB` and
`REDIS_CACHE_DB` must be indexes the trading platform does not use.

## Web and services

```bash
sudo cp deploy/nginx/account.signalmesh.dev.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/account.signalmesh.dev.conf /etc/nginx/sites-enabled/
sudo certbot --nginx -d account.signalmesh.dev
sudo nginx -t && sudo systemctl reload nginx

sudo cp deploy/systemd/accounting-*.service deploy/systemd/accounting-*.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now accounting-queue.service accounting-scheduler.timer
```

Give the accounting application its **own PHP-FPM pool**
(`php8.5-fpm-accounting.sock`). Sharing the trading platform's pool would let a
slow accounting request starve the trading dashboard of workers — a coupling
that shows up only under load, which is the worst time to discover it.

## Deploy ceremony

Backup → migrate → restart → verify. In that order, every time.

```bash
deploy/backup.sh                                  # backs up AND verifies the restore
cd /srv/accounting/app && git pull
bin/check-isolation.sh                            # must pass before anything else
(cd modules/poland && composer install --no-dev && vendor/bin/phpunit)
cd /srv/accounting/foundation && composer install --no-dev
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart php8.5-fpm@accounting accounting-queue.service
php artisan poland:verify-rates
tail -n 100 storage/logs/laravel.log
```

Every schema change ships with a note in `docs/CHANGELOG.md`.

## Backups

`deploy/backup.sh` dumps the database, **restores it into a scratch database and
compares row counts** on the accounting tables, then archives storage. An
unverified backup is a belief, not a backup — so verification is not a separate
job somebody might skip, it is part of taking the backup, and the script exits
non-zero when the counts disagree.

Schedule it daily and alert on a non-zero exit.

## Monitoring that means something

Check the things that would actually be wrong:

- `poland:verify-rates` fails → the tables are running out; a taxpayer is about
  to hit a month the software cannot price.
- Queue depth grows without draining → the worker is dead or looping.
- A settlement carries `is_estimate = true` for a month the taxpayer believes is
  complete → a purchase register is missing.

An HTTP 200 from the application says the web server is up. It does not say the
numbers are right, and it should never be treated as if it did.

## Environments

Development, staging and production get separate databases, separate `.env`
files and separate credentials. KSeF production credentials are never installed
anywhere until the full test suite passes against the KSeF test environment
(Phase 3, `docs/ROADMAP.md`).
