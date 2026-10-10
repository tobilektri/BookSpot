# REST API

[← README](../README.md)

The app talks to its backend exclusively through this JSON API. You can use it for your own scripts
and integrations.

## Basics

- **Base URL**: `https://books.example.org/api/v1/…` (rewritten to `public/api/index.php`). Without
  URL rewriting the same routes work as `…/api/index.php/v1/…`.
- **Authentication**: session cookie from `POST /api/v1/auth/login`. There are no API tokens yet.
- **CSRF**: every state-changing request (`POST`, `PUT`, `PATCH`, `DELETE`) needs the header
  `X-Requested-With: BookSpot`. Browsers cannot send it cross-site without a CORS preflight, which is
  refused unless the origin is listed in `CORS_ALLOWED_ORIGINS`.
- **Method override**: some shared hosts block `PUT`/`PATCH`/`DELETE`. Send `POST` with
  `X-HTTP-Method-Override: PUT` instead – the app always does.
- **Bodies** are JSON (`Content-Type: application/json`), except the CSV import (`multipart/form-data`).
- **Errors** are uniform: HTTP status + `{"error": "<code>", …}`, e.g.
  `{"error": "validation_failed", "errors": {"title": "required"}}`. Codes are stable and translated
  by the app.
- **Rate limits**: `429 {"error": "rate_limited", "retry_after": 20}` with a `Retry-After` header – per
  IP for all requests (`RATE_LIMIT_REQUESTS_PER_MINUTE`) and per user/IP for expensive routes
  (login 10/min, lookup 60/min, import 10/min, batch 30/min …).

```bash
B=https://books.example.org/api/v1
curl -c jar -H 'X-Requested-With: BookSpot' -H 'Content-Type: application/json' \
     -d '{"login":"anna","password":"…"}' $B/auth/login
curl -b jar "$B/books?q=tolstoy&sort=title"
curl -b jar -H 'X-Requested-With: BookSpot' -H 'Content-Type: application/json' \
     -d '{"shelf_id":1,"shelf_col":2,"shelf_row":3,"isbns":["9783548283951"]}' $B/books/batch
```

## Routes

### Session & profile

| Method & path | Auth | |
|---|---|---|
| `GET /auth/status` | – | current user (or `null`), app name, version, whether registration/setup is open |
| `POST /auth/login` | – | `{login, password}` – login = username or e-mail |
| `POST /auth/register` | – | `{username, email, password, locale}` – first account or with `ALLOW_REGISTRATION` |
| `POST /auth/logout` | – | |
| `GET /auth/invites/{token}` · `POST /auth/invites/accept` | – | invitation link: who is invited / `{token, password}` |
| `GET` · `PUT /auth/profile` | user | `{email, locale, current_password, new_password, library_name, library_loan_days}` |
| `POST /auth/onboarded` | user | introduction finished |
| `POST /auth/impersonation/stop` | user | back to the admin account |

### Books

| Method & path | Auth | |
|---|---|---|
| `GET /books` | user | list; query: `q`, `reading_status`, `entry_type`, `genre`, `shelf_id`, `col`, `row`, `unplaced`, `lent`, `status`, `sort` (`title`, `authors`, `recent`, `updated`, `pages`, `year`, `due`), `limit`, `offset`, `compact` |
| `POST /books` | user | create; optional `personal: {reading_status, …}` |
| `GET` · `PUT` · `DELETE /books/{id}` | user | details incl. your reading data / update (only sent fields) / delete |
| `GET /books/isbn/{isbn}` | user | `{found, book}` – catalogue lookup (ISBN-10/13, with or without hyphens) |
| `GET /books/due` | user | active loans by due date |
| `GET /books/genres` | user | genres with counts |
| `POST /books/move` | user | `{ids: [], shelf_id, shelf_col, shelf_row}` |
| `GET` · `PUT /books/{id}/personal` | user | your reading data: `reading_status`, `rating`, `pages_read`, `started_at`, `finished_at`, `notes` |
| `GET /books/enrich-status` · `POST /books/enrich` | user | books with missing data / fill in a few |
| `GET /books/{id}/cover?v=` | user | cover image (JPEG/PNG/WebP), `404` if none |
| `GET /covers/preview?url=…` or `?isbn=…` | user | cover preview fetched by the server, not stored |
| `GET /lookup/{isbn}` | user | `{found, metadata, unavailable}` from the external sources |

### Batch scanning

| Method & path | Auth | |
|---|---|---|
| `POST /books/batch` | user | `{shelf_id, shelf_col, shelf_row, isbns: [...]}` or `{items: [{client_id, isbn, shelf_id, shelf_col, shelf_row}]}`, `move_existing` (default `true`); up to 100 per request |
| `POST /books/batch/process` | user | `{book_ids: [...]}` – works off a few queue entries and returns the current state of those books |

`POST /books/batch` answers immediately:

```json
{ "status": "queued", "queued": 1, "items": [
  { "client_id": null, "isbn": "9783548283951", "status": "created", "book": { "id": 41, "title": "9783548283951", "metadata_status": "pending", … } },
  { "client_id": null, "isbn": "9783404139873", "status": "moved", "previous_location": { "shelf": "Living room", "compartment": "R6" }, "book": { … } }
] }
```

Item status: `created`, `existing`, `existing_here`, `moved`, `duplicate`, `invalid`, `invalid_location`.
Book `metadata_status`: `pending` (queued), `failed` (not found), `null` (done).

### Shelves

| Method & path | Auth | |
|---|---|---|
| `GET /shelves` · `POST /shelves` | user | list / create: `{name, room, description, columns}` with `columns: [{label, rows: ["L1", …]}]` or `[4, 2, 5]` |
| `GET` · `PUT` · `DELETE /shelves/{id}` | user | one shelf incl. its books / update / delete (books keep existing without location) |

When updating `columns`, a compartment may be sent as `{"name": "L1", "from_col": 1, "from_row": 4}` –
the books of the compartment that used to be at column 1 / row 4 move along to the new position (that
is how the drag & drop editor works). Compartments without origin are new and empty.

### Users & administration

| Method & path | Auth | |
|---|---|---|
| `GET /users` | user | admins: all accounts; users: id + username only |
| `POST /users` | admin | `{username, email, password, role, locale, send_invite}` |
| `PUT` · `DELETE /users/{id}` | admin | `{role, email, password}` / delete |
| `POST /users/{id}/invite` | admin | (re)send the invitation link |
| `POST /users/{id}/impersonate` | admin | continue as this user |
| `GET /admin/system` · `POST /admin/cron-token` | admin | cron token, feature status / new token |

### Everything else

| Method & path | Auth | |
|---|---|---|
| `GET /stats?year=` | user | statistics for a year or all time |
| `POST /import` | user | CSV upload (`multipart/form-data`, see [import-export.md](import-export.md)) |
| `GET /export` | user | CSV download |
| `POST /sync` | user | offline queue: `{ops: [{id, type: "personal"\|"move", …}]}` |
| `GET /push` · `POST` · `DELETE /push/subscriptions` · `POST /push/test` | user | Web Push |
| `GET`/`POST /cron?token=` | token | web cron; also accepts the header `X-Cron-Token` |

## Deprecated endpoints

The endpoints of versions before 1.7 (`api/books.php`, `api/auth.php?action=…`, `api/cron.php?token=…`
…) still exist as thin wrappers around the same controllers, so installed old app versions, offline
queues and cron URLs keep working. New integrations should use `/api/v1`.

## Where the routes live

`backend/src/Http/Kernel.php` is the single route table; controllers are in `backend/src/Controller/`.
See [architecture.md](architecture.md) for the request flow and middleware.
