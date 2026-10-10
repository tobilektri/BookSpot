# Configuration (`.env`)

[← README](../README.md)

BookSpot reads `.env` in the project folder (copy `.env.example`). Real environment variables – e.g.
from Docker Compose – take precedence. An empty value means “use the default”.

## Application

| Setting | Default | |
|---|---|---|
| `APP_URL` | – | Public URL, e.g. `https://books.example.org`. Used in invitation links, as contact in requests to the book data sources and as push sender fallback. |
| `CONTACT_EMAIL` | – | Contact address sent in the User-Agent of requests to the book data sources (`BookSpot/1.7.5 (https://books.example.org; you@example.org)`), so they can reach you instead of blocking. Open Library then allows **3 instead of 1 request per second**. |
| `APP_NAME` | “SchmökerSpot” (German UI) / “BookSpot” (English UI) | Display name override. |
| `APP_ENV` | `production` | `development` adds error details to API error responses – never in production. |
| `APP_TIMEZONE` | `Europe/Berlin` | Used for due dates, “today”, statistics. |

## Database

| Setting | Default | |
|---|---|---|
| `DB_DRIVER` | `mysql` | `mysql` (MariaDB/MySQL) or `sqlite`. |
| `DB_HOST`, `DB_PORT` | `localhost`, `3306` | |
| `DB_NAME`, `DB_USER`, `DB_PASS` | – | |
| `DB_SQLITE_PATH` | `var/bookspot.sqlite` | Only for SQLite; relative to the project folder or absolute. |

Tables are created and migrated automatically on the first request (`backend/migrations/`).

## Accounts and sessions

| Setting | Default | |
|---|---|---|
| `ALLOW_REGISTRATION` | `false` | `true` shows a sign-up form on the login page (new accounts get the role *user*). The very first account can always be created and becomes admin. |
| `SESSION_SECURE` | `auto` | `Secure` cookie flag: `auto` = when the request is HTTPS; `true` behind a TLS-terminating proxy. |
| `SESSION_LIFETIME_DAYS` | `30` | How long you stay signed in. |
| `MAIL_FROM` | `noreply@<host>` | Sender of invitation e-mails (PHP `mail()`). Use an address of your own domain, otherwise providers may reject the mail. |
| `MAIL_REPLY_TO` | – | Optional reply-to address. |
| `INVITE_VALID_DAYS` | `7` | Lifetime of invitation links. |

## Cron job

| Setting | Default | |
|---|---|---|
| `CRON_TOKEN` | generated | Token of the web cron URL. Empty: generated once and shown to admins under *Settings → Cron job*. Set it for a fixed token (min. 16 characters). |
| `CRON_QUEUE_BATCH` | `30` | Batch scan placeholders looked up per cron run. |
| `CRON_ENRICH_METADATA` | `true` | Fill in missing book data in the background. |
| `CRON_ENRICH_BATCH` | `20` | Books enriched per cron run. |
| `CRON_COVER_BATCH` | `40` | Covers downloaded ahead of time per cron run. |

## Book data and covers

| Setting | Default | |
|---|---|---|
| `METADATA_LOOKUP` | `true` | Look up book data online at all. |
| `METADATA_DNB` | `true` | Ask the German National Library (free, no key, needs the PHP `dom` extension). |
| `GOOGLE_BOOKS_API_KEY` | – | Your own free key – without it Google answers 429. [How to get one](google-books-api-key.md). |
| `COVER_DNB` | `true` | Publisher covers (VLB) via the German National Library as an additional cover source. |
| `COVER_ALLOW_PRIVATE_HOSTS` | `false` | Covers are only downloaded from public internet addresses (protection against requests to internal services). `true` only if your cover images live on a server in your own network. |

## Rate limits

See [metadata.md](metadata.md#rate-limits) for how they work.

| Setting | Default | |
|---|---|---|
| `RATE_LIMIT_OPEN_LIBRARY` | `1`, with `CONTACT_EMAIL` `3` | Requests per second to the Open Library API (its documented limits). |
| `RATE_LIMIT_DNB` | `2` | Requests per second to the German National Library. |
| `RATE_LIMIT_GOOGLE_BOOKS` | `5` | Requests per second to Google Books. |
| `RATE_LIMIT_OPEN_LIBRARY_COVERS` | `0.33` | Open Library covers by ISBN (Open Library allows 100 per 5 minutes and IP). |
| `RATE_LIMIT_DNB_COVERS` | `2` | Requests per second to the DNB cover service. |
| `RATE_LIMIT_REQUESTS_PER_MINUTE` | `600` | API requests per minute and IP address (cover images don't count). `0` = off. |

## Push notifications

| Setting | Default | |
|---|---|---|
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | generated | Empty: generated once and stored in the database. Or create them with `php backend/bin/vapid.php`. |
| `VAPID_SUBJECT` | `mailto:admin@example.org` | Contact for push services (`mailto:` or `https:`). |

## Other

| Setting | Default | |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | – | Other web front-ends that may call the API with the user's session (exact origins, comma separated). Empty = same origin only, which is what the app needs. Allowed origins can send authenticated requests, so only list trusted ones. |
| `IMPORT_GENRE_BLOCKLIST` | – | Additional comma-separated “genres” the CSV import ignores (on top of the built-in list of Amazon categories). |

## PHP settings

`public/.user.ini` raises the upload limit (16 MB) and the execution time for the CSV import on hosts
that honour `.user.ini`. Docker uses `docker/php.ini`.
