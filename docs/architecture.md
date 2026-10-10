# Architecture & security

[← README](../README.md)

## Goals that shaped the design

- **Runs on cheap shared hosting**: plain PHP + MySQL, deploy via FTP, no SSH, no daemons, no Node.js
  on the server. Everything long-running is either split into small batches (cron, app-driven queue)
  or done by the browser.
- **Small and dependency-light**: vanilla PDO, a ~200-line router, one Composer dependency (Web Push).
- **Private by default**: the browser only talks to your own server.
- **Works offline**: the PWA keeps a copy of the catalogue and queues changes.

## Code layout

```
backend/
  bootstrap.php         autoloader, .env, error handling
  src/
    Http/               Router, Route, Response, Kernel (= route table), Middleware/
    Controller/         one class per API area (Book, Batch, Shelf, Auth, User, …)
    *Service.php        domain logic: BookService, ShelfService, UserBookService, StatsService,
                        MetadataService, CoverService, PushService, ReminderService, InviteService …
    BatchImporter.php   batch scanning + metadata queue
    RateLimiter.php     request limits and throttling of external sources
    Import/             CsvReader, BookImporter
  migrations/{mysql,sqlite}/  numbered SQL files, applied automatically
  bin/                  CLI: cron.php, create-user.php, demo-data.php, dev-router.php, migrate.php, vapid.php
public/                 document root
  api/index.php         API front controller (+ deprecated api/*.php wrappers)
  index.html, assets/, sw.js, manifest.webmanifest   ← built frontend
frontend/               Vue 3 source (Vite, Tailwind CSS 4)
  src/views/            pages; src/components/ building blocks; src/lib/ api, offline db, sync,
                        batch scanning, scanner, push, i18n …; src/sw.js service worker (Workbox)
tests/                  PHPUnit (SQLite in memory, MariaDB in CI)
tools/screenshots/      Playwright script for the images in docs/
```

## Request flow

```
Browser ──► /api/v1/books/12
            .htaccess / nginx: rewrite to api/index.php?_route=/v1/books/12
            Kernel::serve() → Router::dispatch()
              global middleware:  SecurityHeaders → Cors → ErrorHandler → Csrf → RateLimit (per IP)
              route middleware:   Authenticate (user|admin) → RateLimit (per user, expensive routes)
              BookController::show() → BookService → PDO
            ◄── JSON (or image / CSV for covers and export)
```

- The **route path** is passed explicitly (`?_route=`), so it works no matter whether the domain points
  to the project folder (root `.htaccess` forwards to `public/`), to `public/` or to a sub-directory.
  Without rewriting, `api/index.php/v1/…` (PATH_INFO) works too.
- **Controllers** return arrays (→ JSON) or a `Response` (files, downloads). They don't touch
  `$_SERVER` or headers.
- The deprecated `api/books.php` etc. call `Kernel::serve('/books.php')` – the same router, mapped to
  the same controllers by a small `legacy()` method per controller.

## Data model (short)

| Table | |
|---|---|
| `users` | accounts, role `admin`/`user`, locale, library defaults, onboarding flag |
| `books` | the shared catalogue: bibliographic data, type (own/library/personal loan), shelf position (`shelf_id`, `shelf_col`, `shelf_row`), loan fields, cover and metadata state |
| `user_books` | personal data per user and book: reading status, rating, pages read, dates, notes |
| `shelves` | name, room, layout as JSON `[{label, rows: [names]}]` |
| `metadata_queue` | batch scan lookups |
| `push_subscriptions`, `notification_log` | Web Push endpoints, sent reminders (de-duplication) |
| `user_tokens`, `login_attempts`, `settings` | invitations, login throttling, generated secrets (cron token, VAPID keys) |

Migrations are plain SQL per driver, numbered (`001_init.sql` …), recorded in `schema_migrations`
and applied on the first request after an update. An emptied database is recreated automatically.

## Frontend

Vue 3 single-page app with hash routing (works in sub-directories without server rules), built by
Vite into `public/`. State is kept in small reactive modules (`lib/store.js`, `lib/catalog.js`) instead
of a store library. IndexedDB (Dexie) holds the offline catalogue, the offline queue and batch scans;
the Workbox service worker precaches the app shell, caches API reads network-first and covers
cache-first. Details: [offline-pwa.md](offline-pwa.md).

## Security measures

| Area | Measure |
|---|---|
| SQL injection | PDO prepared statements everywhere, no string-built SQL with user input |
| XSS | the API only returns JSON (`nosniff`, `JSON_HEX_TAG`); Vue escapes all output, no `v-html`; strict Content Security Policy (`script-src 'self'`, `img-src 'self'`) |
| CSRF | `X-Requested-With: BookSpot` required for state-changing requests + `SameSite=Strict` cookies; CORS closed unless explicitly configured |
| Sessions | `HttpOnly`, `SameSite=Strict`, `Secure` via HTTPS, session ID regenerated at login |
| Passwords | Argon2id (bcrypt fallback), login throttling per IP |
| Access control | `Authenticate` middleware per route group, admin checks again in the controllers |
| SSRF | cover downloads only to public IPs, DNS pinned, redirects re-checked |
| Abuse | global and per-route request limits (429 with `Retry-After`); outgoing requests throttled |
| Files | `.env`, `backend/`, `vendor/`, `var/` not reachable via the web (document root `public/` or `.htaccess` deny rules) |
| CSV export | formula injection escaped |
| Secrets | `.env` and generated secrets only on the server; Google key never sent to the browser |

Found a security issue? Please report it privately to the maintainer instead of opening a public issue.
