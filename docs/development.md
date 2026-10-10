# Development

[← README](../README.md)

## Local setup without Docker

Requirements: PHP 8.2+ with `pdo_sqlite`, Composer, Node.js 22+.

```bash
composer install
cp .env.example .env              # e.g. DB_DRIVER=sqlite, APP_ENV=development, ALLOW_REGISTRATION=true
cd frontend && npm ci && cd ..

# backend + built frontend on :8000 (dev-router.php does what public/.htaccess does in production)
(cd frontend && npm run build)
php -S localhost:8000 -t public backend/bin/dev-router.php

# or: Vite with hot reload on :5173, API calls are proxied to :8000
cd frontend && npm run dev
```

Demo data for a fresh database (users `anna`, `ben`, `mia`, password `demo-password`):

```bash
DB_DRIVER=sqlite DB_SQLITE_PATH=/tmp/bookspot-demo.sqlite php backend/bin/demo-data.php en   # or de
```

With Docker: see [docker.md](docker.md#development-with-live-reload).

## Tests

```bash
vendor/bin/phpunit                                   # SQLite in memory
TEST_DB_DSN="mysql:host=127.0.0.1;dbname=bookspot_test;charset=utf8mb4" \
TEST_DB_USER=bookspot TEST_DB_PASS=… vendor/bin/phpunit   # MariaDB/MySQL (drops all tables of that DB!)
```

Every test starts with a fresh schema built from the migrations; the clock is frozen
(`App::setNow()`), outgoing HTTP is replaced by fakes, and the rate limiter writes to a temp folder.
Notable suites:

| Test | Covers |
|---|---|
| `RouterTest` | routing, 404/405, CSRF, roles, CORS, request limits, old endpoints = new routes |
| `RateLimiterTest` | bursts, throttling, 429 backoff, fallback to the next source |
| `BatchImporterTest` | batch scanning, queue retries, parallel claims, 60 scans without a single 429 |
| `ImportTest` | Bookstats CSV incl. location codes |
| `MetadataServiceTest`, `CoverServiceTest` | sources, merging, local covers, SSRF protection |

## Frontend

- `frontend/src/views/` pages, `components/` building blocks, `lib/` logic without UI
  (API client, IndexedDB, offline sync, batch scanning, scanner, push, i18n).
- Texts live in `frontend/src/lib/i18n.js` (German and English side by side) – please add both.
- `npm run build` writes to `public/` (`index.html`, `assets/`, `sw.js`, `manifest.webmanifest`).
  The built files are not committed; CI builds them for the release bundle.

## Database changes

Add a new numbered file to **both** `backend/migrations/mysql/` and `backend/migrations/sqlite/`
(e.g. `008_something.sql`). Migrations run automatically; keep them additive and tolerant
(`IF NOT EXISTS`) – shared-hosting users update by uploading files.

## Screenshots and GIFs

The images in `docs/images/` are generated from the demo data with Playwright
(`tools/screenshots/capture.mjs`); ffmpeg is needed for the GIFs, ImageMagick (optional) shrinks the PNGs.

```bash
export DB_DRIVER=sqlite DB_SQLITE_PATH=/tmp/bookspot-demo-en.sqlite METADATA_DNB=false
php backend/bin/demo-data.php en
php -S localhost:8000 -t public backend/bin/dev-router.php &
cd tools/screenshots && npm install && npx playwright install chromium && LOCALE=en node capture.mjs
# repeat with a fresh database, "de" and LOCALE=de
```

The batch scanning GIF uses a generated fake camera video with barcodes; `fill-queue.php` plays the
book data source so no real requests go out.

## Continuous integration

`.github/workflows/ci.yml` runs on every push:

- PHP 8.2 and 8.5 × SQLite and MariaDB: lint + PHPUnit,
- frontend build,
- the **`bookspot-bundle`** artifact (`vendor/` + built frontend, ready for FTP upload),
- on tags `v*`: a GitHub release with `bookspot.zip`.

## Versioning

`App::VERSION` (`backend/src/App.php`) and `frontend/package.json` carry the same version; the app
shows it under *Settings → About*.
