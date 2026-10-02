You are a senior presentation designer and technical storyteller. Create a professional, modern, premium 6-slide project presentation for a software project called **Fleetra**. Base everything strictly on the factual brief below — do not invent features, technologies, numbers, or claims that are not listed.

# Global design direction
- 16:9 widescreen. Clean, academic/project-presentation appropriate, premium and modern.
- Colour system: deep forest green as the primary (#086347, with darker tones #07553D and #063A2B), a lime accent (#8FCF33 / #A7E345), soft sage neutrals (#F7FAF8 background, #E6EEE9 borders), near-black text (#1F2D28) with muted grey-green (#5F736B) for secondary text.
- Typography: a modern geometric sans (Inter or equivalent) for everything; a refined serif (Georgia/serif) only for large hero titles. Consistent heading sizes across all slides.
- Strong visual hierarchy, generous whitespace, aligned grids, consistent iconography (line icons).
- **Every slide must contain at least one meaningful diagram, chart, flow, or architecture visual** that genuinely explains the content — never decorative filler shapes.
- Keep text at a **medium level**: short headline + a few tight lines or bullets per slide. No walls of text, no one-word slides. The presenter should be able to speak from the slides.
- Consistent slide numbers and a thin footer with the project name.

# Project brief (the only source of truth)

**Project name:** Fleetra
**Tagline:** Smart Transport Management
**What it is:** A web-based bus and intercity transport management system — one console that runs fleet, drivers, schedules, bookings, tickets, maintenance, incidents and reporting for a bus operator.

**The problem it solves:** Bus operators today plan routes on whiteboards, track buses, drivers and licences in spreadsheets, and sell seats without any safeguard against conflicts. The result is double-booked buses and drivers, expired licences on the road, seats sold twice, and no reliable operational reporting. Fleetra replaces those disconnected tools with a single operations platform that enforces real transport rules in the database, not just in the interface.

**Who uses it (5 roles):**
1. Administrator — users, roles, settings, audit trail, every module.
2. Transport Manager — fleet, drivers, routes, schedules, maintenance.
3. Dispatcher — dispatch board, vehicle assignment, trip updates, incidents.
4. Driver — a simple duty sheet: today's trips, route, passenger counts, status updates.
5. Passenger — searches buses, books seats, prints/downloads tickets.

**Main features (explain how they work, not just name them):**
- **Bus search** — a passenger enters From / To / travel date and passenger count. Results merge *real scheduled departures* with clearly-labelled *demo services* so a route is never an empty screen. Every result is bookable.
- **"Available buses" board / current bus terminal** — a passenger picks the terminal they are standing in and sees every departure still to come today, in chronological order, based on the current time, with an "Earlier buses" view.
- **Route search & route/stop data** — routes carry distance, estimated duration, base fare and an ordered list of stops; fares are pro-rated to the leg actually travelled.
- **Seat booking and tickets** — a live seat map; a seat is locked with a database constraint so it can never be sold twice; confirmation issues a printable e-ticket with a scannable QR code that staff validate at the gate.
- **Conflict-free scheduling** — before a departure is saved it is validated against real overlap rules and the vehicle's serviceable status, so a bus or driver can never be double-booked. Database unique keys make overlapping assignments impossible.
- **Trips and daily operations** — schedules become trips that drivers start, delay and complete; dispatchers work from a single board; passenger counts and delay reasons are recorded.
- **Maintenance and incidents** — workshop jobs hold a bus out of service automatically until the work is completed; incidents are logged with type and severity and surface operational alerts.
- **Fleet, driver and passenger records** — vehicle specifications, odometer, licence expiry tracking, employment status, bus-to-driver assignment.
- **Reporting** — eight operational reports share one filter bar and export to CSV exactly as displayed: Fleet utilisation, Trip completion, Route performance, Passenger bookings, Revenue, Maintenance cost, Driver trips, and Delay analysis.
- **Excel / data integration** — a large India bus reference workbook (terminals, cities, operators, routes, interstate routes) is imported from an .xlsx file; imports are idempotent and use stable external IDs.
- **Duplicate prevention** — terminals, cities, operators and routes are matched on unique business keys, so re-importing the workbook never creates duplicates; demo services are automatically suppressed whenever a real scheduled service already covers the same origin, destination and time.
- **Demo services** — deterministic, clearly flagged generated departures that fill timetable gaps for a route or terminal, and can be booked and materialised into real routes, buses, drivers and schedules.
- **Passenger-facing extras** — search suggestions/autocomplete for locations, ticket listing, profile, and simulated in-app payments (no real gateway is charged, but the records are real).

**Data scale (real numbers currently in the system):** about 2,000 bus routes, 2,000 buses, 600 drivers, 8,500+ stops, thousands of upcoming scheduled departures, plus terminals/cities/operators reference data covering every Indian state.

**Technology actually used:**
- **Frontend:** server-rendered HTML5, CSS3, JavaScript (vanilla, no build step); Bootstrap 5, Bootstrap Icons and the Inter font, all served locally from `assets/vendor`; custom CSS design-token system for the Fleetra look; Chart.js for report/dashboard charts; Leaflet for maps; a local QR code library for tickets.
- **Backend:** PHP 8 (typed, object-free procedural codebase, no Composer dependencies), PDO, server-side sessions with hardened cookies, CSRF tokens on every state-changing form, `password_hash()`/`password_verify()` bcrypt authentication with login throttling, and role-based access control enforced on the server for every page and AJAX endpoint.
- **Database:** MySQL / MariaDB (InnoDB, utf8mb4) with foreign keys and unique constraints that make double-booking and double-selling impossible; an audit/activity log table records important actions.
- **Data processing:** a custom PHP .xlsx reader (reads the workbook archive/sheets directly) plus an idempotent importer and CLI data-generation tools.
- **Architecture:** a module-based architecture (dashboard + modules for buses, drivers, routes, schedules, trips, bookings, tickets, payments, maintenance, incidents, notifications, reports, users, locations, logs, search) behind a shared application shell (sidebar + topbar) and a public landing page; JSON endpoints for location autocomplete and notifications.

# The 6 slides to produce

**Slide 1 — Title / Front page**
- Project title "Fleetra", subtitle/tagline "Smart Transport Management".
- A short one-line descriptor: "One console to run buses, drivers, routes, bookings and reporting."
- A relevant **project concept visual** — a refined illustration of a bus + route network / journey path, or a stylised operations console. Clean, premium. Do not overload with text.

**Slide 2 — Introduction & Problem**
- What the project is, why it is needed, the problem it solves, and the basic idea behind the system.
- Include a **Problem → Solution diagram**: left side the current pain (whiteboards, spreadsheets, overbooked buses/drivers, expired licences, double-sold seats, no reporting), right side how Fleetra solves each with one platform and rule-enforcing database constraints.
- Medium-length content — understandable, not text-heavy.

**Slide 3 — System / Project Architecture**
- A clear **architecture / block diagram** as the main visual: Users (5 roles) → Browser UI (Bootstrap 5, local assets, Chart.js, Leaflet, QR) → Web server running the PHP 8 module system (shared shell, role-based permission checks, CSRF, sessions) → PDO → MySQL database (routes, buses, drivers, schedules, trips, bookings, tickets, payments, maintenance, incidents, audit log). Show the Excel workbook + CLI data generators feeding the database, and location/notification JSON endpoints used by the UI.
- Label the interactions/arrows (authentication, search, booking, seat-lock, scheduling validation, reporting).

**Slide 4 — Main Features & Working**
- Explain how the key features work: bus search, current-terminal / available-buses board, upcoming schedule, route & stop search, seat booking with database-level seat locking, QR e-ticket, conflict-free scheduling, Excel import + duplicate prevention, and demo services.
- Include a **user workflow / bus-search flow diagram**: Search (From → To → date) → results merge real + demo services → select a bus → live seat map → seat locked → booking confirmed → payment (simulated) → QR e-ticket → boarding scan.
- Medium detail; a mix of concise explanation and the flow visual.

**Slide 5 — Technology / Implementation**
- Explain how it was built using only the real stack: PHP 8 (no Composer), MySQL/MariaDB, PDO, Bootstrap 5 + vanilla JS served locally, Chart.js, Leaflet, QR library, custom xlsx reader + idempotent importer, CLI data generators, hardened sessions/CSRF/bcrypt/RBAC, audit logging.
- Include a **technology stack / data-flow diagram** grouping Frontend, Backend, Database, and Data/Integration layers, with arrows showing a request flowing from browser → PHP module → permission check → PDO → MySQL → rendered view, and the Excel workbook flowing through the importer into the database.
- Medium level of detail, no invented tools.

**Slide 6 — Conclusion & Thank You**
- Summarise what was achieved (one platform replacing spreadsheets and whiteboards; rule-enforcing database that prevents double-booking and double-selling; passenger booking with QR tickets; eight exportable reports; ~2,000 routes / ~2,000 buses of real-looking data; five role dashboards).
- How it solves the identified problem and the key benefits.
- A short "possible future improvements" line (e.g., live GPS tracking, online payment gateway, mobile app).
- A clean **Thank You** section with a subtle outcome/roadmap visual.
- Keep it visually clean and not overloaded.

# Output rules
- Exactly **6 slides**, in the order above, each with at least one meaningful diagram/visual.
- Professional, modern, premium, visually balanced, consistent typography, colour and spacing.
- Medium-length, presentation-friendly copy — no huge paragraphs and no empty slides.
- Use only the facts above. Do not add technologies, features, or numbers that are not in this brief.
