# Fleetra — Smart Bus & Transport Management System

A professional transport operations platform built with **pure PHP, MySQL, Bootstrap 5 and
vanilla JavaScript**. No frameworks, no build step — it runs directly on XAMPP.

Covers the full journey of a transport company: fleet and driver records, routes and stops,
timetables, trip operations, passenger search and seat booking, printable/PDF tickets, workshop
maintenance, incident reporting, notifications, reporting and CSV export — all behind one
role-aware console.

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
> - **Phase 7 — Smart features:** **incidents**, **maintenance**, **notifications**, and an
>   India-wide **location & terminal directory** with search-as-you-type autocomplete.
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
| Internet | Only for the OpenStreetMap tiles on the incident-location map. Every vendor asset is served locally from `assets/vendor/`, so the rest of the app works offline. |

---

## 2. Installation

1. **Start XAMPP** — launch Apache and MySQL from the XAMPP Control Panel.
2. **Import the database** — open <http://localhost/phpmyadmin> →
   **Import** → choose `database/fleetra_db.sql` → **Import**.
   *(The script creates `fleetra_db` itself, so no database needs to be selected first.
   It is safe to re-run: it drops and recreates the tables and reseeds the demo data.)*
3. **Import the India bus catalogue** — in phpMyAdmin, **Import** →
   `database/excel_bus_catalog.sql` → **Import**.
   This adds the **1,023 terminals, 902 cities, 86 routes and 39 operators**
   from the workbook in the project root. It is idempotent — re-importing never
   creates a duplicate. *(If you prefer, skip this file and run
   `php tools/import_excel.php --apply` instead; see
   [Bus catalogue import](#bus-catalogue-import) below.)*
4. **Open the app** — <http://localhost/fleetra/>

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
│   ├── maintenance/   index (+?view=due diary)  create  edit  view  delete  _form  _logic
│   ├── incidents/     index  create  view  status         _logic
│   ├── notifications/ index  send  read                   _logic
│   ├── reports/       index  export                       _logic   (8 reports, CSV)
│   ├── logs/          index (activity / audit log)
│   └── settings/      index                               _logic
├── api/           notifications.php  locations.php     (AJAX endpoints)
├── config/        config.php  database.php             ← only place with DB credentials
├── includes/      auth.php  permissions.php  functions.php  operations.php
│                  nav.php  header.php  sidebar.php  topbar.php  footer.php
│                  alerts.php  auth-header.php  auth-footer.php
│                  locations.php  bus_catalog.php  schedule_seeder.php
│                  xlsx_reader.php  excel_importer.php
├── tools/         import_excel.php  generate_schedules.php  (CLI utilities)
├── assets/
│   ├── css/       style.css  responsive.css  landing.css
│   ├── js/        app.js
│   ├── images/
│   └── vendor/    bootstrap  bootstrap-icons  chartjs  fonts  leaflet  qrcode
├── database/      fleetra_db.sql  excel_bus_catalog.sql
│                  migrations/    (one-off migrations for existing databases)
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

### Bus catalogue import

The project root ships an Excel workbook (`India_Bus_Terminals_and_Routes_EXPANDED_2026-10-02.xlsx`)
that is the primary source for terminals, cities, routes and operators. It is imported into the
catalogue tables:

```bash
php tools/import_excel.php --stats    # show what the workbook contains
php tools/import_excel.php --sql      # regenerate database/excel_bus_catalog.sql
php tools/import_excel.php --apply    # import straight into the database
```

The PHP importer reads the `.xlsx` itself (`includes/xlsx_reader.php`, no Composer or PHP zip
extension needed) and every insert is **idempotent**: terminals and cities are matched on the
unique `(city, name, state)` key, operators on their code and routes on their external id. Running
it again never duplicates a record and never overwrites a row an administrator has since edited.
Because the unique keys use a case-insensitive collation, `Kolkata`, `kolkata` and `KOLKATA`
resolve to one terminal. Each run is recorded in `data_import_runs`.

**Turning imported routes into bookable departures.** A route on its own is not a
departure — nothing can be sold until it has a dated schedule. `includes/schedule_seeder.php`
closes that gap: for the imported routes a passenger is actually looking at, it backfills the
metrics the workbook left at zero (distance, journey time, base fare), spreads each route's stop
arrival offsets so the seat picker can order them, and writes real departures for the next few
days, assigning a bus and a driver to a free `(bus, date, time)` / `(driver, date, time)` slot so
the database's unique keys are never violated. The search page runs this automatically for the
routes it shows, and it is idempotent — a route with an upcoming departure is never touched.

To pre-build the whole timetable instead of letting the first search request do it:

```bash
php tools/generate_schedules.php --days=14
```

### Search buses and Available buses
`modules/search/index.php` is split into two clearly separated sections:

- **Search buses** — From / To / date / passengers with type-ahead suggestions, rendered as clean
  bus cards (bus, operator, from → to, departure, arrival, duration, stops, seats, fare, type).
- **Available buses** — *Your current bus terminal*: search or quick-pick the terminal you are in
  and see every departure still to come from it, in chronological order for the current time,
  across the rest of the day. Already-departed services are hidden unless the explicit
  **Earlier buses** toggle is used.

Both sections read one catalogue (`includes/bus_catalog.php`) that merges real scheduled
departures with generated **demo services**. Demo services fill routes and terminals with no
scheduled bus yet so the board is never empty during testing, are always flagged (dashed border,
*Demo service* chip) and can never duplicate a real departure — a demo is skipped whenever the
same origin / destination / departure time already exists. Demo services are generated
deterministically from the live catalogue rather than hardcoded, and follow the same data
structure as real buses. **Demo services are bookable too**: selecting one posts its identity to
`modules/search/book_demo.php`, which materialises just that departure — route, stops, bus,
driver and schedule — via `includes/demo_booking.php` and then opens the normal seat picker, so
a demo ticket is a real ticket with a real booking and QR code. Imported catalogue routes are
also given real schedules up front by `includes/schedule_seeder.php` (above), so on repeat
searches demo services shrink to only the times a real timetable does not cover.

### Public landing page
`index.php` renders a marketing page for guests and redirects signed-in users to their role
dashboard. It includes a sticky nav, a hero with a **working search form** that submits to the
real search module, a database-derived metric strip, featured capabilities, the three most
popular routes (from live booking counts), a role overview, and — on a local install — a
demo-account panel. Styling lives in `assets/css/landing.css`, layered on the same design
tokens as the console.

### Location reference data and autocomplete
`database/locations` seeds the India-wide bus directory: **377 real bus terminals, stands, stops
and landmarks across all 36 states and union territories**, each with city, district, state,
ISO state code and approximate coordinates, plus alternate/former-name aliases (Bangalore,
Calcutta, Bombay, Mysore...). `includes/locations.php` searches it by an indexed generated
`search_text` column (city, name, district, aliases, state), and `api/locations.php` serves the
autocomplete used by the public landing search and the passenger Find-a-bus page.

**Location permission is never required** — typed input always works; the optional "use my
current location" button is user-initiated, and declining it falls back to manual entry.
Administrators curate the directory in `modules/locations/` (search, filter, paginate, add, edit,
remove).

### Find a bus — separate From/To and clean bus cards
`modules/search/index.php` presents two clearly separated fields — **From** ("Enter your current
location") and **To** ("Enter destination") — with a swap control, date and passengers, and
autocomplete on both. Results are rendered as clean bus cards (bus/operator, from/to, departure,
arrival, duration, stops, seats free, fare, bus type) classified only by the timetable:
**Available**, **Departed** or **Unavailable** (cancelled, in the workshop or fully booked).
The full passenger journey is: search → select seats → book/pay → view, print or download the
PDF ticket.

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
  bookings, tickets, passengers, locations, maintenance, incidents, notifications, reports,
  logs, settings).
- Manager: 200 on fleet, maintenance, reports, passengers and incidents; **403** on users,
  settings, logs, tickets and the admin dashboard.
- Dispatcher: 200 on trips, bookings, buses and incident reporting; **403** on
  bus creation, maintenance, reports, passengers and users.
- Driver: 200 on their own dashboard, trips, notifications and incident reporting; **403** on
  another driver's trip, the incident list, search, buses and maintenance.
- Passenger: 200 on dashboard, search, bookings, tickets and notifications; **403**
  on another passenger's booking, ticket validation, incidents, passengers, users and reports.
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
- Locations: autocomplete returns ranked matches (city, terminal, aliases) and finding a
  location never requires browser/device permission; the optional "use my location" helper
  degrades to manual entry when declined.
- Reports: all eight reports render with rows and a chart, and all eight CSV exports return
  200 with a UTF-8 BOM; a bogus report key returns 404.
- Settings: valid saves persist; invalid email/phone/out-of-range values are rejected with
  "Nothing was saved" and the database is left unchanged.

Every dashboard and module page renders with real database data and zero PHP notices or
warnings; `logs/fleetra.log` records no failed queries.

---

## 11. Future work

The schema and modules are deliberately shaped so these can be added without re-architecting:

- RFID / barcode hardware feeding `modules/tickets/validate.php`.
- Online payment gateways replacing the simulated payment step.
- Email/SMS delivery for the existing notification rows.
- Demand forecasting, route optimisation and predictive maintenance built on the accumulated
  trip, booking and maintenance history.
