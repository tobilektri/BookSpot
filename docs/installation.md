# Installation on shared hosting

**English** · [Deutsch](de/installation.md) · [← README](../README.md)

BookSpot needs no SSH access, no Node.js and no background daemon. A web space with PHP, a database
and FTP is enough. This guide was written against [Lima-City](https://www.lima-city.de/?cref=253166)
*(referral link¹)*, but works the same with any Apache-based host.

## Requirements

| | Required | Recommended |
|---|---|---|
| PHP | 8.2 or newer | 8.5 |
| PHP extensions | `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `json`, `openssl` | `curl` (faster lookups), `gd` (scales covers down), `dom` (German National Library), `gmp` (faster push encryption) |
| Database | MySQL 8 / MariaDB 10.6+ with `utf8mb4` – or SQLite (no database server at all) | MariaDB (CI tests run on MariaDB 11.4) |
| Web server | Apache with `mod_rewrite` and `.htaccess` (`AllowOverride All`) | – |
| HTTPS | **required** for camera, service worker, push and secure cookies | a free Let's Encrypt certificate |

Most shared hosts let you pick the PHP version per domain in their admin panel.

## 1. Get the package

Download **`bookspot.zip`** from the [releases](https://github.com/tobilektri/BookSpot/releases). If
there is no release yet, take the artifact **`bookspot-bundle`** of the latest successful
[CI run](https://github.com/tobilektri/BookSpot/actions/workflows/ci.yml). The bundle already contains
the PHP dependencies (`vendor/`) and the built app (`public/assets`, `public/sw.js` …).

Building it yourself instead:

```bash
composer install --no-dev --optimize-autoloader
cd frontend && npm ci && npm run build    # writes into ../public
```

## 2. Upload

Unpack the archive and upload everything via FTP/SFTP into a folder of your web space, e.g.
`/bookspot`. Keep the folder structure – `backend/`, `vendor/` and `var/` must sit next to `public/`.

## 3. Point the domain at the app

Create a (sub)domain such as `books.example.org` and set its **target directory**. Both variants work:

| Document root | How it works |
|---|---|
| **`bookspot/public/`** (recommended) | Only the public part is reachable at all. |
| **`bookspot/`** (project folder) | The root `.htaccess` forwards every request internally to `public/` and answers `403` for `backend/`, `vendor/`, `var/`, `tests/`, `.env`, `*.sql`, `*.log` … |

Installing into a sub-directory (`example.org/books/`) works as well – the app uses relative paths.

## 4. Database and `.env`

1. Create a database (character set **`utf8mb4`**, collation `utf8mb4_unicode_ci`) and a user for it.
2. Copy `.env.example` to **`.env`** (in the project folder, not in `public/`) and set at least:

   ```dotenv
   APP_URL=https://books.example.org
   DB_HOST=localhost
   DB_NAME=bookspot
   DB_USER=bookspot
   DB_PASS=your-password
   ```

   Without a MySQL database: `DB_DRIVER=sqlite` – the file is created in `var/`.
3. Optional but worthwhile: a free [Google Books API key](google-books-api-key.md) for book data in
   other languages, `MAIL_FROM` for invitation e-mails, and `CONTACT_EMAIL` – sent along with requests
   to the book data sources, so Open Library allows 3 instead of 1 request per second.

All settings: [configuration.md](configuration.md).

## 5. Make `var/` writable

`var/` holds sessions, logs, the downloaded covers (`var/covers/`), rate limiter state and – with
SQLite – the database. Give the web server write access (usually the default on shared hosting;
otherwise `chmod 775 var` in the FTP client).

## 6. First start

Open the site. With an empty database BookSpot creates its tables automatically and shows the
**first-run setup**: the first account becomes the **admin**. Then the introduction wizard explains the
app; as an admin you can import a CSV and invite the rest of the household right away
([users & roles](users-and-roles.md)).

## 7. Cron job

A web cron URL triggers the work that happens without anyone looking:

- push reminders for due loans (once per book and day, so repeated calls are harmless)
- the metadata queue of the [batch scanner](scanning.md#batch-scanning)
- background enrichment of book data and downloading covers ahead of time

The complete URL including a token is shown to admins under **Settings → Cron job**, e.g.

```
https://books.example.org/api/v1/cron?token=…
```

Enter it in your hoster's cron job feature (at Lima-City: *Cronjobs* in the admin panel) or with an
external service such as cron-job.org. **Every 10–15 minutes** is a good interval; hourly works too.
The token is generated automatically – set `CRON_TOKEN` in `.env` if you prefer a fixed one.
URLs of older versions (`api/cron.php?token=…`) keep working.

## 8. Push notifications (optional)

Nothing to do: the VAPID keys for Web Push are generated on first use and stored in the database.
Each person enables push on their device under *Settings → Push notifications*. iOS requires the app
to be installed to the home screen first – see [loans & reminders](loans-and-reminders.md#push-notifications).

## Updating

1. Back up (see below).
2. Upload the new bundle **without** overwriting `.env` and `var/`.
3. Open the site – database migrations run automatically.
4. In the installed app, tap **“Reload”** in the update banner (the service worker then switches to the new version).

Files that no longer exist in the new version can stay; if a release note asks you to delete
something (e.g. the old `public/icons/` folder), do so.

## Backups

Everything that matters:

- the **database** (export via phpMyAdmin/Adminer, or copy `var/bookspot.sqlite`),
- **`var/covers/`** – the downloaded cover images,
- your **`.env`**.

A CSV export (*Settings → Export*) is a nice extra – it is Bookstats-compatible and can be imported again.

## Something does not work?

[Troubleshooting](troubleshooting.md) covers the usual suspects: install button missing, 404 with
`public/` as document root, camera not starting, push not arriving.

---

¹ *Referral link: if you sign up with Lima-City through this link, the maintainer may receive a
commission. BookSpot runs on any host that meets the requirements.*
