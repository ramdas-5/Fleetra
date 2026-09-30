# Fleetra — Smart Bus & Transport Management System

A professional transport operations platform built with **pure PHP, MySQL, Bootstrap 5 and
vanilla JavaScript**. No frameworks, no build step — it runs directly on XAMPP.

Covers the full journey of a transport company: fleet and driver records, routes and stops,
timetables, live trip operations, passenger search and seat booking, printable QR tickets,
GPS tracking, workshop maintenance, incident reporting, notifications, reporting and CSV
export — all behind one role-aware console.

> **Build status: complete — all nine development phases implemented.**
>
> - **Phase 1 — Foundation:** folder structure, schema + seed data, PDO layer, shared layout,
>   design system, auth helpers, role-based access control.
> - **Phase 2 — Authentication:** login, register, logout, forgot/reset password, profile with
>   photo upload, change password.
> - **Phase 3 — Admin dashboard:** sidebar, topbar, KPI cards, Chart.js analytics, fleet
>   overview, upcoming trips, recent bookings, alerts and maintenance-due panels.
> - **Phase 4 — Core master data:** CRUD for **buses**, **drivers**, **routes** and **stops**
>   (with reordering), plus **users**.
> - **Phase 5 — Operations:** **schedules** (with bus/driver conflict detection), **trips** and
>   the trip status machine.
> - **Phase 6 — Passenger system:** bus search, seat map, booking, cancellations, **tickets**
>   with printable QR, booking history, passenger directory.
> - **Phase 7 — Smart features:** live **tracking** (Leaflet + OSM) with GPS simulation,
>   **incidents**, **maintenance**, **notifications**.
> - **Phase 8 — Reporting:** eight reports with filters + Chart.js and UTF-8 CSV export, plus
>   the **activity log** and **settings** screens.
> - **Phase 9 — Final polish:** UI consistency, responsive behaviour, validation, permissions,
>   security, empty states, loading feedback — see *Verified behaviour* below.
>
> Plus a **public landing page** (`index.php`) with a live search form, database-derived
> metrics and demo-account panel. Every sidebar entry now resolves to a real page — there are
> no "Soon" tags and no dead links anywhere in the app.

---

## 1. Requirements

| Component | Version used |
|-----------|--------------|
| XAMPP    | Apache + MySQL/MariaDB 10.4 + PHP 8 |
| PHP      | 8.0 or newer (uses typed properties, `match`, `never` return type) |
| PHP extensions | `pdo_mysql` (required), `mbstring` (required), `fileinfo` (recommended) |
| Database | MariaDB 10.4 / MySQL 5.7+ (generated columns are used for seat locking) |
| Browser  | Any modern browser |
| Internet | Only for the OpenStreetMap tiles on the live map. Every vendor asset is served locally from `assets/vendor/`, so the rest of the app works offline. |

---

## 2. Installation

1. **Start XAMPP** — launch Apache and MySQL from the XAMPP Control Panel.
2. **Import the database** — open <http://localhost/phpmyadmin> →
   **Import** → choose `database/fleetra_db.sql` → **Import**.
   *(The script creates `fleetra_db` itself, so no database needs to be selected first.
   It is safe to re-run: it drops and recreates the tables and reseeds the demo data.)*
3. **Open the app** — <http://localhost/fleetra/>

If your project folder is not named `fleetra`, no configuration is needed — `BASE_URL` is
detected automatically from `DOCUMENT_ROOT`. Only the `config/database.php` credentials would
need editing if your MySQL user is not `root` with an empty password.

The landing page renders even before the database is imported (it degrades gracefully and
hides statistics that need the database), so a fresh clone shows something meaningful at once.

---

## 3. Demo accounts

Every seeded account uses the password **`Fleetra@123`**
(bcrypt hash stored in `users.password` — plain-text passwords are never persisted).

| Role | Email | Lands on |
|------|-------|----------|
| Administrator | `admin@fleetra.com` | `/admin/dashboard.php` |
| Transport Manager | `manager@fleetra.com` | `/manager/dashboard.php` |
| Dispatcher | `dispatcher@fleetra.com` | `/dispatcher/dashboard.php` |
| Driver | `driver1@fleetra.com` (also `driver2@`, `driver3@`) | `/driver/dashboard.php` |
| Passenger | `passenger@fleetra.com` (also `rahul.verma@`, `meera.iyer@`) | `/passenger/dashboard.php` |

The login screen and the landing page list these accounts with a one-click **Use account**
button. That panel is only rendered while `APP_ENV` is `'local'` (see `config/config.php`).

### Seeded data

9 users · 5 buses · 3 drivers · 3 routes · 16 stops · 12 schedules · 12 trips ·
7 bookings · 5 tickets · 6 payments · 6 bus positions · 6 maintenance records ·
10 notifications · 2 incidents · 7 activity log entries · 13 settings.

Schedule and trip statuses are derived from the clock **at import time**, so the dashboard
always shows a realistic mix of completed, running and upcoming services, and the last
departure of the day (21:30) stays available for the live demo.

---

## 4. Project structure

```
fleetra/
├── index.php                  Public landing page (guests) / role redirect (signed in)
├── login.php  register.php  logout.php
├── forgot-password.php  reset-password.php
├── profile.php  change-password.php
├── admin/        dashboard.php            (Administrator)
├── manager/      dashboard.php            (Transport Manager)
├── dispatcher/   dashboard.php            (Dispatcher)
├── driver/       dashboard.php            (Driver duty view)
├── passenger/    dashboard.php            (Passenger home)
├── modules/
│   ├── buses/         index  create  edit  view  delete   _form  _logic
│   ├── drivers/       index  create  edit  view  delete   _form  _logic
│   ├── routes/        index  create  edit  view  delete  stops  _form  _logic
│   ├── users/         index  create  edit  view  delete   _form  _logic
│   ├── schedules/     index  create  edit  view  cancel  delete  _form  _logic
│   ├── trips/         index  view  status                _logic
│   ├── search/        index (results)  seats (seat map + booking)    _logic via bookings
│   ├── bookings/      index  create  view  cancel                _logic
│   ├── tickets/       index  view  validate  status
│   ├── passengers/    index  view
│   ├── tracking/      index (Leaflet map)  simulate  _logic
│   ├── maintenance/   index (+?view=due diary)  create  edit  view  delete  _form  _logic
│   ├── incidents/     index  create  view  status         _logic
│   ├── notifications/ index  send  read                   _logic
│   ├── reports/       index  export                       _logic   (8 reports, CSV)
│   ├── logs/          index (activity / audit log)
│   └── settings/      index                               _logic
├── api/           notifications.php  tracking.php      (AJAX endpoints)
├── config/        config.php  database.php             ← only place with DB credentials
├── includes/      auth.php  permissions.php  functions.php  operations.php
│                  nav.php  header.php  sidebar.php  topbar.php  footer.php
│                  alerts.php  auth-header.php  auth-footer.php
├── assets/
│   ├── css/       style.css  responsive.css  landing.css
│   ├── js/        app.js
│   ├── images/
│   └── vendor/    bootstrap  bootstrap-icons  chartjs  fonts  leaflet  qrcode
├── database/      fleetra_db.sql
├── docker/        entrypoint.sh                        (container port handling)
├── uploads/       profiles/  buses/
├── logs/          fleetra.log  php-error.log         (created at runtime)
├── .env.example   Environment template (copy to .env)
├── Dockerfile     For Render / Koyeb / Fly / Railway
└── DEPLOY.md      Free deployment guide
```

Each module keeps its form markup in `_form.php` and its validation in `_logic.php`, so
create and edit always apply identical rules instead of duplicating them. Shared operational
rules (time comparison, schedule cancellation, passenger notification) live in
`includes/operations.php` so they exist in exactly one place.

---

## 5. Roles and permissions

Capabilities are declared in one place — `includes/permissions.php` — and support wildcards
(`admin` holds `*`, `manager` holds `fleet.*`). Adding a role later means adding it to
`role_labels()`, giving it a capability list and pointing `dashboard_path()` at its dashboard.

| Area | Admin | Manager | Dispatcher | Driver | Passenger |
|------|:-----:|:-------:|:----------:|:------:|:---------:|
| Fleet, drivers, routes, stops | ✅ manage | ✅ manage | view only | — | — |
| Schedules | ✅ manage | ✅ manage | view only | — | — |
| Trips (operate / update status) | ✅ | ✅ | ✅ | own trips | — |
| Live tracking | ✅ | ✅ | ✅ | send position | view |
| Maintenance | ✅ | ✅ | — | report | — |
| Incidents | ✅ | ✅ | report / view | report | — |
| Bookings | ✅ | view | ✅ manage | — | own |
| Tickets | ✅ | — | — | — | own |
| Passengers directory | ✅ | ✅ | — | — | — |
| Reports | ✅ | ✅ | — | — | — |
| Notifications (own centre) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Send announcements | ✅ | ✅ | ✅ | — | — |
| Users, settings, activity log | ✅ | — | — | — | — |

Every restricted page calls `require_role()` / `require_permission()` **on the server**, so
URL manipulation cannot bypass authorisation. Ajax endpoints re-check permissions and return a
JSON `403`; normal pages render a branded `403` page.

---

## 6. Security implementation

- **Prepared statements everywhere** via PDO (`ATTR_EMULATE_PREPARES => false`); no string-built SQL.
- **Password hashing** with `password_hash()` / `password_verify()`; automatic re-hash when the
  algorithm changes. A dummy hash is compared for unknown emails so response timing cannot be
  used to enumerate accounts.
- **CSRF tokens** on every state-changing form and AJAX POST, verified with `hash_equals()`;
  failures return 419 and are logged.
- **Session hardening** — strict mode, `HttpOnly`, `SameSite=Lax`, `Secure` under HTTPS,
  ID regeneration on login, 60-minute idle timeout, user-agent fingerprint checks.
- **Login throttling** — 5 failed attempts per email/browser triggers a 15-minute cooldown.
- **Output escaping** — `e()` (`htmlspecialchars`) on all user data, or `e_js()` inside scripts.
- **Single-use password resets** — only the SHA-256 digest of the token is stored; links expire
  after 60 minutes and are burned on use.
- **Safe uploads** — MIME + size validation, random filenames, and `.htaccess` blocking script
  execution inside `uploads/`.
- **Friendly errors** — `display_errors` is off; details go to `logs/`, users see a branded page.
- **Hardened directories** — `config/`, `includes/`, `database/`, `logs/` deny direct web access,
  plus a PHP-level guard that refuses direct requests even without `.htaccess`.
- **Tamper-proof ticket codes** — a printed/QR code is a signed payload; the validator rejects
  altered signatures, so a guessed or edited code will not verify.

---

## 7. Notable features

### Public landing page
`index.php` renders a marketing page for guests and redirects signed-in users to their role
dashboard. It includes a sticky nav, a hero with a **working search form** that submits to the
real search module, a database-derived metric strip, featured capabilities, the three most
popular routes (from live booking counts), a role overview, and — on a local install — a
demo-account panel. Styling lives in `assets/css/landing.css`, layered on the same design
tokens as the console.

### Live tracking and GPS simulation
`modules/tracking/` draws buses on a Leaflet + OpenStreetMap map, refreshed by polling
`api/tracking.php`. Because a local XAMPP box has no GPS hardware, positions can be advanced
with a simulation tool that walks each active bus one step along its route's stop polyline.
Simulated and real fixes are deliberately separated by the `bus_locations.source` column
(`simulated` vs `device`), and every simulated reading is labelled as such in the UI. Real
devices can post fixes to `api/tracking.php`; the bus and trip are resolved from the signed-in
driver's own active duty, so a spoofed `bus_id` is ignored.

### Tickets and QR codes
Booking issues a ticket with a human-readable number and a signed code. `modules/tickets/view.php`
renders a printable ticket (reusing the existing print stylesheet) with the QR drawn client-side
by a locally vendored `qrcode.min.js`. `modules/tickets/validate.php` is the boarding-gate
screen; staff can stamp a ticket **used** or **void** it.

### CSV export
`modules/reports/export.php` streams the selected report as CSV with a UTF-8 BOM, a filter
preamble and the report summary, so it opens correctly in Excel. PDF export is **not**
implemented and is not claimed anywhere in the UI.

---

## 8. Configuration and deployment

Settings are read by `config/env.php`, which resolves each value from (in order) a real
environment variable, a key in the project `.env` file, or the local XAMPP default. Nothing
has to be edited in PHP to deploy, and an existing XAMPP install is unaffected because the
defaults are unchanged.

| Setting | Purpose |
|---------|---------|
| `APP_ENV` | `local` (default) shows demo credentials; set `production` on any public URL |
| `SHOW_DEMO_CREDENTIALS` | Force the demo panel on/off regardless of `APP_ENV` |
| `APP_TIMEZONE` | Business timezone used for all schedules and reports |
| `APP_CURRENCY` | Currency symbol for fares, tickets and revenue |
| `SESSION_IDLE_TIMEOUT` | Idle logout timer (default 3600 s) |
| `APP_BASE_URL` | Override base-URL auto-detection on unusual host layouts |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | Database credentials |
| `DB_SSL_CA` `DB_SSL_VERIFY` | TLS settings for managed MySQL hosts that require SSL |

Copy `.env.example` to `.env` and fill it in:

```bash
cp .env.example .env
```

Database credentials live only in `config/database.php`, pulled from the environment — they
are not repeated anywhere else in the codebase. `.env` holds secrets, is git-ignored, and is
blocked from the browser by the root `.htaccess`.

**Deploying to a free host?** See **[DEPLOY.md](DEPLOY.md)** for a step-by-step guide
(InfinityFree needs no credit card and takes about 15 minutes), the exact environment
variables to set, a post-deploy hardening checklist and a troubleshooting table. A
`Dockerfile` is included for Render/Koyeb/Fly/Railway.

**Email is not configured.** On a local install, `forgot-password.php` displays the reset link
on screen instead of sending mail, clearly labelled as a development behaviour. Wiring up SMTP
is a production task.

---

## 9. Data integrity rules

These are enforced on the server and covered by the verification run below.

| Rule | Behaviour |
|------|-----------|
| Bus/driver/route with history | Cannot be hard deleted — archived instead (inactive / resigned) so tickets, trips and reports stay accurate |
| Duplicate bus number or registration | Rejected with a field-level error |
| Driver with an expired licence | Cannot be assigned to a bus |
| Inactive or workshop bus | Cannot be assigned to a driver |
| Bus already paired with another driver | Assignment refused, naming the current driver |
| Stop positions | Always saved as a contiguous 1..N sequence, even after insert-in-middle, moves and deletes |
| Overlapping bus/driver schedules | Blocked at the database level (unique keys on bus_id/driver_id + date + departure) |
| Booking the same seat twice | Blocked by a stored generated column with a unique index |
| Booking a cancelled schedule | Refused server-side |
| Cancelling a schedule | Cancels linked bookings, refunds paid fares, voids tickets and notifies passengers in one transaction |
| Bus maintenance state | Derived automatically — a bus flips to `maintenance` while a job is open and back to `active` when all jobs close, never overwriting an intentional `inactive` |

---

## 10. Verified behaviour

Checked against a live PHP 8 / MariaDB 10.4 stack — **108 PHP files lint clean**, and every
page was crawled over HTTP for all five roles plus guests.

**Access control (HTTP status per role)**
- Guests are redirected (302) from every guarded page; the landing page returns 200.
- Admin: 200 on every module page (buses, drivers, routes, stops, users, schedules, trips,
  bookings, tickets, passengers, tracking, maintenance, incidents, notifications, reports,
  logs, settings).
- Manager: 200 on fleet, maintenance, reports, passengers and incidents; **403** on users,
  settings, logs, tickets and the admin dashboard.
- Dispatcher: 200 on trips, tracking, bookings, buses and incident reporting; **403** on
  bus creation, maintenance, reports, passengers and users.
- Driver: 200 on their own dashboard, trips, notifications and incident reporting; **403** on
  another driver's trip, the incident list, the tracking page, search, buses and maintenance.
- Passenger: 200 on dashboard, search, bookings, tickets, tracking and notifications; **403**
  on another passenger's booking, ticket validation, incidents, passengers, users, reports and
  the simulation tool.
- An access-denied page renders for every rejected request; zero unexpected errors.

**Authentication**
- Login, role-based redirects and logout for all five roles.
- Wrong password rejected with a friendly message; 5-attempt throttle enforced.
- Self-registration validation (weak passwords, duplicate email, mismatched confirmation).
- Password reset end to end, including rejection of reused and expired tokens.
- Changing the sign-in email requires the current password; avatars upload, replace and remove.

**Operational pipelines exercised end to end**
- Booking: seat reserved → booking confirmed/paid → ticket issued → duplicate seat refused →
  invalid seat refused → cancellation refunds and frees the seat.
- Tickets: short code, uppercase code and full QR payload all verify; a tampered payload or a
  wrong signature does not. Stamping **used** and **voiding** both persist and notify the holder.
- Incidents: creation notifies the right staff (3 for a critical report), triage moves the
  record through investigate → resolve, sets `resolved_at`, and notifies the reporter.
- Notifications: unread counts, mark-one, mark-all, AJAX + form fallback, and an announcement
  broadcast reaching exactly the selected audience. A bad CSRF token returns 419.
- Maintenance: full create → edit → delete round-trip, with the affected bus's status and
  odometer reconciled each time.
- Tracking: `simulate.php` appends a position labelled `simulated`; a device fix from a driver
  with an active duty is accepted and a spoofed `bus_id` is ignored; with no active duty the
  request is refused.
- Reports: all eight reports render with rows and a chart, and all eight CSV exports return
  200 with a UTF-8 BOM; a bogus report key returns 404.
- Settings: valid saves persist; invalid email/phone/out-of-range values are rejected with
  "Nothing was saved" and the database is left unchanged.

Every dashboard and module page renders with real database data and zero PHP notices or
warnings; `logs/fleetra.log` records no failed queries.

---

## 11. Future work

The schema and modules are deliberately shaped so these can be added without re-architecting:

- Real GPS ingestion from a mobile app via the existing `api/tracking.php` device endpoint.
- RFID / barcode hardware feeding `modules/tickets/validate.php`.
- Online payment gateways replacing the simulated payment step.
- Email/SMS delivery for the existing notification rows.
- Demand forecasting, route optimisation and predictive maintenance built on the accumulated
  trip, booking and maintenance history.
