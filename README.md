# Tribal Weave Archive of the Philippines

A dynamic web application that preserves and presents Philippine Indigenous textile traditions: woven textiles, bark cloth and plaited fiber. Visitors browse a public, searchable archive. Registered members submit field documentation. Administrators review every submission (including Free and Prior Informed Consent documentation) before it is published.

**WAD 1 Final Project**

**Group 6:** 
1. Banania, Ian 
2. Borromeo, Nicole Irrah
3. Castillo, Ashley
4. Cenon, Symeon
5. Macaubos, Carl Andrae

---

## Run it

**Option A: PHP built-in server (recommended for demos)**

```bash
cd tribal-weave-archive
php setup.php                       # creates/seeds the database (a ready-made one is already committed)
php -S localhost:8000 router.php    # router.php blocks private folders
```

Open <http://localhost:8000>.

**Option B: XAMPP**

1. Copy the project folder into `C:\xampp\htdocs\` (for example `htdocs\tribal-weave-archive`).
2. Start Apache from the XAMPP control panel.
3. Open `http://localhost/tribal-weave-archive/` (the `.htaccess` files protect the private folders).

All URLs in the code are relative, so the app works from the site root or from a sub-folder.

**setup.php**

| Command                           | What it does                                                                                               |
|-----------------------------------|------------------------------------------------------------------------------------------------------------|
| `php setup.php`                   | Creates any missing tables and seeds an empty database. Never wipes existing data. Safe to run repeatedly. |
| `php setup.php --reset`           | CLI only: deletes the database file and rebuilds it from scratch.                                          |
| `http://localhost:8000/setup.php` | Same as the first command, from the browser.                                                               |

**Images:** the seed data refers to image files such as `Tnalak.png`, `Inabel.png`, `Seputangan.png`, `Maranao.png`, `mandaya.jpg`. Keep them **in the project root next to `index.html`** (the same place as in the original frontend). If a file is missing, the page shows a built-in SVG textile placeholder instead of a broken image. Member uploads are stored in `uploads/`.

## Demo accounts

|--------|----------|--------------|
| Admin  | `admin`  | `Admin@123`  |
| Member | `member` | `Member@123` |

These accounts are created by `setup.php` and stored **hashed**. They are documented here only; no credentials exist in the HTML or JavaScript. **Change both passwords (Profile, Change Password) before any real deployment.**

## Features

**Guest (not logged in)**
- Landing page, Home, Tribes directory, Tribe details (history, culture, practices and the tribe's weaves)
- Weaves gallery with live server-side search plus region and craft-medium filters, and a detail page for every weave
- Sign up, log in, forgot password

**Member**
- Everything a guest can do
- Submit weaving records with image upload (new records are `PENDING`)
- Member dashboard with status cards (All, Approved, Pending, Rejected) that filter the table
- Edit or delete their own PENDING or REJECTED records; read the admin's feedback; Revise & Resubmit
- Profile: edit name and email, change password, "My Contributions", logout

**Admin**
- Everything a member can do
- Dashboard with live counters, a pending approvals queue (Approve, or Reject with mandatory feedback), edit and delete of published records, and a member management table
- "Add Archival Record": records added by an admin are published immediately (`APPROVED`)

## Database

| Table | Purpose | Relationships |
|---|---|---|
| `users` | Accounts (`password_hash`, role `ADMIN`/`MEMBER`) | 1 user has many weaves |
| `regions`, `mediums`, `vulnerability_levels` | Lookup tables | each has many weaves |
| `tribes` | 4 featured tribes with full profiles + one row per other tribe | 1 tribe has many weaves |
| `weaves` | The archive records and their review status | FKs to `tribes`, `regions`, `mediums`, `vulnerability_levels`; `submitted_by` -> `users` (ON DELETE SET NULL) |
| `review_logs` | Audit trail of approve/reject decisions | `weave_id` -> `weaves` (CASCADE), `admin_id` -> `users` |
| `login_attempts` | Brute-force throttling | keyed by identifier |
| `password_resets` | Hashed single-use reset tokens | `user_id` -> `users` (CASCADE) |

The schema is in `database/schema.sql`. Foreign keys are switched on for every connection (`PRAGMA foreign_keys = ON` in `config/db.php`).

## How it works

**PDO connection.** `config/db.php` exposes `db()`, one shared PDO connection with exceptions enabled and real prepared statements. Every query in the project goes through prepared statements with bound parameters (`run_query()` in `includes/helpers.php`); no SQL is built from user input.

**Architecture.** `index.html` is a single-page app. `assets/js/app.js` uses hash routes (`#/weaves`, `#/weave/12`, `#/tribe/3`, `#/profile`, `#/admin` ...) so Back, Forward and refresh work. It talks to four JSON APIs (`api/auth.php`, `weaves.php`, `tribes.php`, `admin.php`) through `fetch()` in `assets/js/api.js`. Responses look like `{ "success": true, "message": "...", "data": ... }` with proper HTTP codes (200, 201, 400, 401, 403, 404, 409, 422, 429, 500). The frontend never stores users or records itself.

**CRUD walkthrough (weaves).**
- *Create:* `POST weaves.php?action=create` validates every field, resolves region/medium/vulnerability against the lookup tables, finds or creates the tribe, generates a unique catalog code (`ATWKP-M-0001`) and inserts the row.
- *Read:* `list` (filters and search use bound `LIKE` parameters) and `get`. Guests only ever receive `APPROVED` records; a non-approved record is visible only to its owner or an admin.
- *Update:* `update` lets a member edit only their own PENDING/REJECTED record (which returns it to PENDING); an admin can edit any record.
- *Delete:* `delete` is allowed for an admin (any record) or for a member (own PENDING/REJECTED only). Only files we created in `uploads/` are removed from disk; the bundled seed images are never touched.

**Approval workflow.** Member submits -> `PENDING` (invisible to guests) -> Admin approves (`APPROVED`, public) or rejects with required feedback (`REJECTED`) -> Member revises and resubmits -> `PENDING` again. Each decision runs in a transaction and is written to `review_logs`.

**Authentication (explainable in one minute).**
- *Hashing:* passwords are stored only as `password_hash($pw, PASSWORD_DEFAULT)` (salted bcrypt) and checked with `password_verify()`. `password_needs_rehash()` upgrades old hashes silently at login. No password or hash is ever returned by an endpoint.
- *Password policy:* at least 8 characters with a letter and a number (checked in JavaScript and again in PHP).
- *Login:* by username **or** email, case-insensitive. One generic error message for wrong password and unknown account, and a dummy `password_verify` on unknown accounts so timing does not reveal which usernames exist.
- *Throttling:* after 5 failed attempts for an identifier within 15 minutes the API answers `429` with "try again in N minutes". A successful login clears the counter.
- *Sessions:* `use_strict_mode`, cookies only, HttpOnly, SameSite=Lax (and Secure over HTTPS), a new session id on login, register and password change. "Remember me" gives a 30-day cookie; otherwise it is a browser-session cookie with a 30-minute idle timeout. Logout destroys the session data **and** expires the cookie. Sessions are stored in `storage/sessions/` so "remember me" survives PHP's default 24-minute cleanup.
- *CSRF:* `auth.php?action=me` returns a token; every POST sends it in the `X-CSRF-Token` header and the server checks it with `hash_equals()`.
- *Authorization:* enforced on the server by `require_login()`, `require_role('ADMIN')` and ownership checks. Client-side guards are only for convenience. Registration always creates a `MEMBER`; any role sent by the client is ignored.
- *Forgot password:* the server creates a random single-use token, stores **only its SHA-256 hash** (30-minute expiry) and always answers "If that email is registered, a reset link has been sent." Setting a new password invalidates that token and all other pending tokens of the user.
- *XSS and uploads:* every dynamic value is escaped with `escapeHtml()` before it reaches the page. Uploads are limited to JPG/PNG/WEBP/GIF (real MIME check, not the extension), 3 MB, with random file names; PHP execution in `uploads/` is blocked.

**Simulated reset e-mails.** There is no mail server, so the reset "email" is written to `storage/mail_outbox.log`. Open that file, copy the link (`http://localhost:8000/#/reset?token=...`) into the browser, and set the new password. The log is blocked from web access and ignored by Git. If the app is reachable under a different host name, set the environment variable `TWA_APP_URL` (for example `http://localhost/tribal-weave-archive`) so the links are built correctly.

## Project structure

```
index.html                 single-page frontend (Tailwind CDN, Lucide, Google Fonts)
assets/css/style.css       styles moved out of index.html
assets/js/api.js           fetch wrapper (CSRF header, error handling)
assets/js/app.js           router, views, forms, dashboards
api/auth.php  weaves.php  tribes.php  admin.php
config/db.php              PDO connection
includes/helpers.php       JSON, sessions, CSRF, auth guards, validation, uploads
database/schema.sql        tables, constraints, indexes
database/app.sqlite        the SQLite database (committed on purpose)
setup.php                  create tables + seed data
router.php  .htaccess      block private folders (built-in server / Apache)
uploads/  storage/         member images / sessions + simulated mail outbox
```
