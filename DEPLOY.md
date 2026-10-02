# Deploying Fleetra for free

This guide takes Fleetra from your XAMPP folder to a public URL **without paying anything**
and **without a credit card** on the recommended path.

Fleetra is plain PHP 8 + MySQL with no build step, so it runs on ordinary shared hosting.
You do not need Composer, Node, Git or a terminal on the server.

**Recommended path:** [Option A — InfinityFree](#option-a--infinityfree-recommended).
It bundles PHP 8.3, MySQL and free SSL, needs no credit card, and takes about 15 minutes.

---

## What "deploy ready" means in this project

The app is already hardened for a public URL:

| Concern | How it is handled |
|---------|-------------------|
| **Credentials** | Never hardcoded. Read from real env vars, then a `.env` file, then local XAMPP defaults — see `config/env.php`. |
| **Environment flag** | `APP_ENV=production` turns off the demo-credential panels. Defaults to `local` so XAMPP is unaffected. |
| **Secrets on disk** | `.env` is git-ignored, and the root `.htaccess` blocks dotfiles, `.sql`, `.log` and `.md` from the browser. |
| **Sensitive folders** | `config/`, `includes/`, `database/`, `logs/` deny web access via `.htaccess` **and** a PHP-level guard that works even when `.htaccess` is ignored. |
| **Uploads** | `uploads/.htaccess` refuses to execute anything script-like. |
| **PHP version** | A friendly message is shown instead of a blank page if the host runs PHP 7. |
| **DB errors** | Details go to `logs/`, users see a branded page. |
| **TLS databases** | Managed MySQL that requires SSL is supported via `DB_SSL_CA`. |
| **Containers** | A `Dockerfile` + `docker/entrypoint.sh` are included for Render/Koyeb/Fly/Railway. |

There are three secrets you must set on any host: **the database credentials** and
**`APP_ENV=production`**.

---

## Quick chooser

| Host | Truly free? | Card? | PHP | MySQL | Best for |
|------|-------------|-------|-----|-------|----------|
| **InfinityFree** | Yes, forever | No | 8.3 | Included (400 DBs) | **Recommended** — simplest, no card |
| Byet.host | Yes, forever | No | 8.3 | Included | Same infra as InfinityFree, more bandwidth |
| TinkerHost | Yes, forever | No | 8.x | 1 database | Light demo, small bandwidth cap |
| AwardSpace | "Free" | No | **7 only** ⚠️ | Included | **Skip** — PHP 7 is unsupported by Fleetra |
| Koyeb | Free allowance | Verification | Native PHP | Bring your own | Git-push workflow, modern workflow |
| Render | Free allowance | Verification | Via Docker | Bring your own | Container deploys (sleeps when idle) |

> **Heads-up on the free container hosts (Koyeb, Render):** their free tiers do **not**
> include MySQL, so you must point Fleetra at an external database. Aiven offers an
> always-free MySQL (1 GB, no credit card) — see
> [Option C](#option-c--koyeb-or-render-containers-with-free-external-mysql).

---

## Option A — InfinityFree (recommended)

**What you get free:** PHP 8.3, MySQL/MariaDB, free SSL (Let's Encrypt), a free subdomain
(e.g. `fleetra.rf.gd`) or your own domain, 5 GB disk, FTP access.

**Known limits to design around:** ~10 second PHP execution limit, no SSH, no cron jobs,
~50,000 hits/day fair-use cap, and no outbound connections from PHP. Fleetra works within
all of these — the only internet use is your browser fetching OpenStreetMap tiles for the
incident-location map, which is not affected.

### A1. Create the account and website

1. Sign up at <https://www.infinityfree.com/> (free, no card).
2. In the client area, click **Create Account** and choose a free subdomain
   (for example `fleetra-demo.rf.gd`).

### A2. Create the database

1. Open the **Control Panel (vPanel)** for your account.
2. Go to **MySQL Databases** → **Create Database**.
3. Write down the four values it shows you:
   - **Host** — looks like `sqlXYZ.infinityfree.com` (not `localhost`)
   - **Database name** — looks like `if0_12345678_fleetra`
   - **Username** — looks like `if0_12345678`
   - **Password** — on InfinityFree this is your **account (vPanel) password**

### A3. Import the schema

1. In vPanel open **phpMyAdmin**.
2. Select your database in the left sidebar.
3. Go to the **Import** tab → **Choose File** → `database/fleetra_db.sql` → **Import**.
4. Import the India bus catalogue the same way: **Import** →
   `database/excel_bus_catalog.sql` → **Import**. It adds the terminals, cities, routes and
   operators from the workbook and is safe to import more than once.

> The SQL file starts with `CREATE DATABASE IF NOT EXISTS fleetra_db; USE fleetra_db;`.
> That is harmless here: phpMyAdmin imports into the database you selected, and the
> `USE` line is ignored on shared hosting. If your host refuses the file, delete the
> first two statements (`CREATE DATABASE ...` and `USE ...`) and import again.

### A4. Upload the files

1. In vPanel open **File Manager** (or use **FileZilla** with the FTP details from vPanel —
   host `ftpupload.net`, your vPanel username and password).
2. Go **inside `htdocs/`**. Your site root *is* `htdocs/`, so the contents of the Fleetra
   folder must land directly there — you should end up with `htdocs/index.php`,
   `htdocs/config/`, `htdocs/modules/`, and so on. **Do not** create `htdocs/fleetra/`.
3. Upload everything **except** `.git/` and your local `logs/`.
   Keep `.htaccess` files — some FTP clients hide dotfiles, so make sure they transfer.

### A5. Create the `.env` file

In `htdocs/`, create a file named exactly `.env` (no `.txt` extension) with:

```ini
APP_ENV=production
DB_HOST=sqlXYZ.infinityfree.com
DB_PORT=3306
DB_NAME=if0_12345678_fleetra
DB_USER=if0_12345678
DB_PASS=your-vpanel-password
APP_TIMEZONE=Asia/Kolkata
APP_CURRENCY=₹
```

Substitute your own values from step A2. That is the entire server configuration —
no PHP file needs editing.

### A6. Open the site and log in

Visit your subdomain. You should see the Fleetra landing page.

Log in with a seeded account and **change the passwords immediately** (see
[Post-deploy checklist](#post-deploy-checklist)):

| Role | Email | Password |
|------|-------|----------|
| Administrator | `admin@fleetra.com` | `Fleetra@123` |
| Transport Manager | `manager@fleetra.com` | `Fleetra@123` |
| Dispatcher | `dispatcher@fleetra.com` | `Fleetra@123` |
| Driver | `driver1@fleetra.com` | `Fleetra@123` |
| Passenger | `passenger@fleetra.com` | `Fleetra@123` |

### A7. Turn on SSL

vPanel → **SSL Certificates** → request the free Let's Encrypt certificate for your domain.
Once issued, HTTPS works automatically; Fleetra already sets the session cookie to `Secure`
when it detects HTTPS.

---

## Option B — Byet.host or TinkerHost

Identical workflow to InfinityFree (same company, same control panel style):

- **Byet.host** — 5 GB disk, unlimited bandwidth, unlimited MySQL (MariaDB), PHP 8.3.
  The better pick if InfinityFree's hit cap bites.
- **TinkerHost** — 1 GB storage, 5 GB/month bandwidth, one MySQL database, PHP 8.
  Fine for a demo; the bandwidth cap is small.
- **AwardSpace** — skip it. Its free plan still serves **PHP 7**, which Fleetra
  (PHP 8.0+) will refuse to run.

Steps: create account → create database → import `database/fleetra_db.sql` via phpMyAdmin →
upload `htdocs/` contents over FTP → add your `.env` → set `APP_ENV=production`.

---

## Option C — Koyeb or Render (containers) with free external MySQL

Use this when you want `git push` deployments instead of FTP.

### C1. Get a free MySQL database

The always-free **Aiven MySQL** plan (1 GB storage, 1 GB RAM, automated backups,
**no credit card**) works well: <https://aiven.io/free-mysql-database>.

After creating it, note the host, port, database, user and password, and
**download the CA certificate** (`ca.pem`).

Alternatively, use **Clever Cloud** MySQL or any MySQL host that allows remote connections.

### C2. Deploy the container

The `Dockerfile` in the project root builds a PHP 8.3 + Apache image with the required
`pdo_mysql` and `mbstring` extensions and honours the platform's `$PORT`.

**Koyeb** (<https://www.koyeb.com/>):
1. Create a Web Service → **GitHub** → select your Fleetra repository.
2. Builder: **Dockerfile**. Port: **80**.
3. Add the environment variables below and deploy.

**Render** (<https://render.com/>):
1. **New → Web Service** → connect the repo.
2. Runtime: **Docker** → Deploy.
3. Note: Render's free web services **sleep after 15 minutes** of inactivity and take
   30–60 seconds to wake — fine for a demo, poor for a public site.

### C3. Environment variables

Set these in the platform dashboard (do **not** commit a `.env` to Git):

```
APP_ENV=production
APP_TIMEZONE=Asia/Kolkata
APP_CURRENCY=$

DB_HOST=your-db-host
DB_PORT=your-db-port
DB_NAME=your-db-name
DB_USER=your-db-user
DB_PASS=your-db-password

# Required by Aiven and other managed MySQL hosts:
DB_SSL_CA=/var/www/html/ca.pem
```

The `Dockerfile` copies the whole project — including any `ca.pem` you place in the
repository root — to `/var/www/html/`, so that path works as-is. **Only do that if the
repository is private**, since a CA bundle in a public repo is not ideal.

### C4. Import the schema

From your own machine, pointing at the remote database:

```bash
mysql -h your-db-host -P your-db-port -u your-db-user -p your-db-name < database/fleetra_db.sql
mysql -h your-db-host -P your-db-port -u your-db-user -p your-db-name < database/excel_bus_catalog.sql
```

Or paste `database/fleetra_db.sql` and then `database/excel_bus_catalog.sql` into your provider's
MySQL console.

**Upgrading an existing database?** Run the one-off migration instead of re-importing:

```bash
mysql -h your-db-host -P your-db-port -u your-db-user -p your-db-name < database/migrations/2026-10-02_bus_catalog.sql
```

### C5. Let the platform pick the port

No action needed — `docker/entrypoint.sh` rewrites Apache to listen on the `$PORT` the
platform injects, then starts Apache normally.

---

## Post-deploy checklist

Do these right after the first successful login:

1. **Set `APP_ENV=production`.**
   Without it, the login screen and landing page advertise the demo accounts. Verify by
   opening `/login.php` — the "Use account" panel must be gone.
2. **Log in as `admin@fleetra.com` and change the password** (Profile → Change Password),
   then change the password for every other demo account or delete the ones you do not need.
3. **Delete the demo accounts you will not use** — Users → delete `driver2`, `driver3`,
   `rahul.verma@`, `meera.iyer@`. Fleetra archives users that have history instead of
   hard-deleting them, which keeps reports accurate.
4. **Delete or archive the sample trips, bookings and tickets** you do not want in a
   portfolio demo.
5. **Confirm HTTPS is active**, then log out and back in so the session cookie is reissued
   as `Secure`.
6. **Exercise a booking end to end** — search, pick a seat and download the ticket PDF;
   the incident map tiles also need your browser to have internet access.
7. **Check `logs/php-error.log` and `logs/fleetra.log`** after a click-through. Both should
   stay small; the root `.htaccess` keeps them off the web.

---

## Troubleshooting

| Symptom | Cause and fix |
|---------|---------------|
| "Fleetra needs PHP 8.0 or newer" | Host is on PHP 7. Switch the PHP version in the control panel (InfinityFree: vPanel → PHP version). |
| "We could not connect to the Fleetra database" | Wrong `.env` credentials, or MySQL is down. Check `DB_HOST` — on free shared hosts it is **not** `localhost`. |
| "The Fleetra database tables are missing or incomplete" | `database/fleetra_db.sql` was not imported, or was imported into the wrong database. |
| Site shows the landing page but every page 500s | Usually a writable-permissions problem. Make `logs/` and `uploads/` writable (chmod `775`) in File Manager. |
| CSS, JS or icons 404 after upload | Dotfolders or `assets/vendor/` did not transfer. Re-upload `assets/` in full; some FTP clients skip dotfiles, and `assets/vendor/` is large. |
| Login "succeeds" but you bounce back to the form | Cookies blocked, or `BASE_URL` is misdetected. Set `APP_BASE_URL=/` (or `/fleetra/`) in `.env`. |
| "Your session expired or the form was tampered with" | The session cookie was lost (often because the URL changes between `http` and `https`). Stay on one hostname and enable SSL. |
| Password reset link never arrives | **Expected** — email is not configured. The reset link is shown on screen only when `APP_ENV=local`. In production, use the admin's Users screen to reset a password manually. |
| Map is blank but the rest of the app works | Tiles come from OpenStreetMap and need the browser to be online. The server itself needs no internet access. |
| Uploads fail | Free hosts cap upload size (~10 MB on several). Fleetra accepts images well under that. |

---

## Going further (still free)

- **Always-free VM** — Oracle Cloud's Always Free tier (24 GB RAM across ARM instances)
  is the most generous way to run a real LAMP stack. It asks for a card for **identity
  verification only**; Always Free resources are not charged.
- **Google Cloud e2-micro** — always free in three US regions, but only 1 GB egress/month.
- **Custom domain** — all the shared hosts above let you point a domain you own at the site
  for free.
