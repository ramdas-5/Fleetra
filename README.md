# Fleetra — Smart Bus & Transport Management System

A professional transport operations platform built with **pure PHP, MySQL, Bootstrap 5 and
vanilla JavaScript**. No frameworks, no build step — it runs directly on XAMPP.

> **Build status: Phases 1, 2 and 4 complete.**
>
> - **Phase 1 — Foundation:** project structure, database schema and seed data, PDO layer,
>   shared layout, design system, authentication helpers, role-based access control.
> - **Phase 2 — Authentication:** login, register, logout, forgot/reset password, profile with
>   photo upload, change password.
> - **Phase 4 — Core master data:** full CRUD for **buses**, **drivers** and **routes**, plus
>   stop management with reordering.
> - **Also done:** role dashboards for all five roles.
>
> Still to build: schedules, trips, bookings, tickets, live tracking, maintenance, incidents,
> notifications, reports, users and settings. The sidebar tags every unbuilt module **Soon**
> and links only to pages that actually exist, so there are no dead links anywhere in the app.

---

## 1. Requirements

| Component | Version used |
|-----------|--------------|
| XAMPP    | Apache + MySQL/MariaDB 10.4 + PHP 8 |
| PHP      | 8.0 or newer (uses typed properties, `match`, `never` return type) |
| Database | MariaDB 10.4 / MySQL 5.7+ (generated columns are used for seat locking) |
| Browser  | Any modern browser |

---

## 2. Installation

1. **Start XAMPP** — launch Apache and MySQL from the XAMPP Control Panel.
2. **Import the database** — open <http://localhost/phpmyadmin> →
   **Import** → choose `database/fleetra_db.sql` → **Import**.
   *(The script creates `fleetra_db` itself, so no database needs to be selected first.
   It is safe to re-run: it recreates the tables and reseeds the demo data.)*
3. **Open the app** — <http://localhost/fleetra/>

If your project folder is not named `fleetra`, no configuration is needed — `BASE_URL` is
detected automatically from `DOCUMENT_ROOT`. Only the `config/database.php` credentials would
need editing if your MySQL user is not `root` with an empty password.

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

The login screen lists these accounts with a one-click **Use account** button. That panel is
only rendered while `APP_ENV` is `'local'` (see `config/config.php`).

### Seeded data

9 users · 5 buses · 3 drivers · 3 routes · 16 stops · 12 schedules · 12 trips ·
7 bookings · 5 tickets · 6 payments · 6 maintenance records · 10 notifications ·
2 incidents · 7 activity log entries · 13 settings.

Schedule and trip statuses are derived from the clock **at import time**, so the dashboard
always shows a realistic mix of completed, running and upcoming services, and the last
departure of the day (21:30) stays available for the live demo.

---

## 4. Project structure

```
fleetra/
├── index.php                  Entry point — routes users by role
├── login.php  register.php  logout.php
├── forgot-password.php  reset-password.php
├── profile.php  change-password.php
├── admin/        dashboard.php            (Administrator)
├── manager/      dashboard.php            (Transport Manager)
├── dispatcher/   dashboard.php            (Dispatcher)
├── driver/       dashboard.php            (Driver)
├── passenger/    dashboard.php            (Passenger)
├── modules/
│   ├── buses/    index.php  create.php  edit.php  view.php  delete.php
│   │              _form.php  _logic.php
│   ├── drivers/  index.php  create.php  edit.php  view.php  delete.php
│   │              _form.php  _logic.php
│   ├── routes/   index.php  create.php  edit.php  view.php  delete.php
│   │              stops.php  _form.php  _logic.php
│   └── …         schedules/ trips/ bookings/ maintenance/ reports/  (next phases)
├── api/          AJAX + future REST endpoints
├── config/       config.php  database.php          ← only place with DB credentials
├── includes/     auth.php  permissions.php  functions.php  nav.php
│                 header.php  sidebar.php  topbar.php  footer.php  alerts.php
│                 auth-header.php  auth-footer.php
├── assets/       css/style.css  css/responsive.css  js/app.js  images/
├── database/     fleetra_db.sql
├── uploads/      profiles/  buses/
└── logs/         fleetra.log  php-error.log         (created at runtime)
```

Each module keeps its form markup in `_form.php` and its validation in `_logic.php`, so
create and edit always apply identical rules instead of duplicating them.

---

## 5. Roles and permissions

Capabilities are declared in one place — `includes/permissions.php` — and support wildcards
(`admin` holds `*`, `manager` holds `fleet.*`). Adding a role later means adding it to
`role_labels()`, giving it a capability list and pointing `dashboard_path()` at its dashboard.

| Area | Admin | Manager | Dispatcher | Driver | Passenger |
|------|:-----:|:-------:|:----------:|:------:|:---------:|
| Fleet & drivers management | ✅ | ✅ | view only | — | — |
| Routes, stops, schedules | ✅ | ✅ | view only | — | — |
| Trips (operate / update status) | ✅ | ✅ | ✅ | own trips | — |
| Live tracking | ✅ | ✅ | ✅ | update | view |
| Maintenance | ✅ | ✅ | — | report | — |
| Incidents | ✅ | ✅ | report | report | — |
| Bookings & tickets | ✅ | view | ✅ | — | own |
| Reports | ✅ | ✅ | — | — | — |
| Users, logs, settings | ✅ | — | — | — | — |

Every restricted page calls `require_role()` / `require_permission()` **on the server**, so
URL manipulation cannot bypass authorisation. Verified: an administrator receives HTTP 403
when requesting the passenger dashboard.

---

## 6. Security implementation

- **Prepared statements everywhere** via PDO (`ATTR_EMULATE_PREPARES => false`); no string-built SQL.
- **Password hashing** with `password_hash()` / `password_verify()`; automatic re-hash when the
  algorithm changes. A dummy hash is compared for unknown emails so response timing cannot be
  used to enumerate accounts.
- **CSRF tokens** on every state-changing form, verified with `hash_equals()`; failures return 419.
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

---

## 7. Configuration notes

Everything lives in `config/config.php`:

| Setting | Purpose |
|---------|---------|
| `APP_ENV` | `'local'` shows demo credentials and on-screen reset links; use `'production'` for a live install |
| `APP_TIMEZONE` | Business timezone used for all schedules and reports |
| `APP_CURRENCY` | Currency symbol for fares, tickets and revenue |
| `SESSION_IDLE_TIMEOUT` | Idle logout timer (default 3600 s) |

Database credentials are isolated in `config/database.php` (`localhost`, `root`, empty password,
`fleetra_db`) and are not repeated anywhere else in the codebase.

**Email is not configured.** On a local install, `forgot-password.php` displays the reset link
on screen instead of sending mail, clearly labelled as a development behaviour. Wiring up SMTP
is a production task.

---

## 8. Data integrity rules

These are enforced on the server and covered by the verification run below.

| Rule | Behaviour |
|------|-----------|
| Bus/driver/route with history | Cannot be hard deleted — archived instead (inactive / resigned) so tickets, trips and reports stay accurate |
| Duplicate bus number or registration | Rejected with a field-level error |
| Driver with an expired licence | Cannot be assigned to a bus |
| Inactive or workshop bus | Cannot be assigned to a driver |
| Bus already paired with another driver | Assignment refused, naming the current driver |
| Stop positions | Always saved as a contiguous 1..N sequence, even after insert-in-middle, moves and deletes |
| Deleting a stop used by bookings | Allowed, and the affected booking count is reported |
| Overlapping bus/driver schedules | Blocked at the database level (unique keys on bus_id/driver_id + date + departure) |
| Booking the same seat twice | Blocked by a stored generated column with a unique index |

---

## 9. Verified behaviour

Checked against a live XAMPP PHP/MySQL 10.4 stack (48 PHP files lint clean, `app.js`
syntax-checked with Node):

**Authentication**
- Login, role-based redirects and logout for all five roles.
- Wrong password rejected with a friendly message; 5-attempt throttle enforced.
- Self-registration validation (weak passwords, duplicate email, mismatched confirmation).
- Password reset end to end, including rejection of reused and expired tokens.
- Changing the sign-in email requires the current password.
- Avatar upload, replacement and removal (file deleted from disk, not just unlinked).
- Change password verifies the current password, blocks reuse of the same password and
  revokes outstanding reset links.

**Access control**
- Guests redirected to login; wrong-role requests receive HTTP 403.
- Dispatcher (read-only for master data) gets 200 on list/view/stops pages, 403 on every
  create, edit and delete page, and the Add/Edit controls are not rendered for them at all.

**Master data CRUD (all exercised over HTTP)**
- Buses: create, edit, delete-without-history, archive-with-history.
- Drivers: create (account + profile in one transaction), edit, unassign, delete-without-history,
  archive-with-history, expired-licence block, occupied-bus block, inactive-bus block.
- Routes: create, edit, delete-without-history (stops cascade), retire-with-history.
- Stops: add, insert at a chosen position, move up/down, edit, delete with renumbering.
- Validation rejects duplicates, out-of-range capacity, and identical source/destination —
  and no partial rows or stray uploads are left behind.

Every dashboard and module page renders with real database data and zero PHP notices or warnings.

---

## 10. Next phase

**Phase 5 — Operations:** schedules (with bus/driver conflict detection), trip generation and
live trip status updates.

Then Phase 6 (passenger search, seat booking, tickets) and Phase 7 (live tracking simulation,
incidents, maintenance, notifications).
