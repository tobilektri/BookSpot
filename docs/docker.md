# Docker

[← README](../README.md)

The repository contains a production-ready Compose stack. You need Docker with the Compose plugin.

```bash
git clone https://github.com/tobilektri/BookSpot.git && cd BookSpot
cp .env.example .env     # set at least DB_PASS, DB_ROOT_PASS and APP_URL
docker compose up -d --build
```

Open <http://localhost:8080> (or `HTTP_PORT`) and create the first account – it becomes the admin.

## Containers

| Service | Image | Purpose |
|---|---|---|
| `web` | nginx (built from `Dockerfile`, target `web`) | serves the built frontend, forwards `/api/v1/…` and `api/*.php` to PHP-FPM, nothing else is executed |
| `app` | PHP 8.5-FPM (target `app`) | the backend; OPcache, GD (JPEG/WebP), GMP, BCMath |
| `cron` | same image as `app` | runs `php backend/bin/cron.php` every 10 minutes: reminders, metadata queue, covers |
| `db` | `mariadb:11.4` | database with health check, `utf8mb4` |

The image is a multi-stage build: Node builds the Vue app, Composer installs the PHP dependencies
(`--no-dev`), and only the result ends up in the runtime images.

## Configuration

Compose passes the settings from your `.env` (next to `docker-compose.yml`) into the containers as
environment variables – see [configuration.md](configuration.md). Docker-specific ones:

| Variable | Default | |
|---|---|---|
| `HTTP_PORT` | `8080` | port of the `web` container on the host; `127.0.0.1:8080` = only reachable through a proxy on the same machine |
| `DB_ROOT_PASS` | **required** | MariaDB root password (only used by the `db` container) |
| `DB_NAME`, `DB_USER`, `DB_PASS` | `bookspot`, `bookspot`, **required** | created by MariaDB on first start and used by the app |

`DB_PASS` and `DB_ROOT_PASS` have no defaults: `docker compose` stops with “Set DB_PASS in .env” until
they are set. **Updating from 1.9 or older without these lines in `.env`?** The database was created with
the former defaults – add `DB_PASS=bookspot_pass` and `DB_ROOT_PASS=root_secret_pass` to `.env` (and
change them afterwards with `ALTER USER` if you like).

`DB_DRIVER`, `DB_HOST` and `DB_PORT` are fixed to the `db` container.

## Data and backups

| Volume | Content |
|---|---|
| `mariadb_data` | the database |
| `app_var` | `var/`: sessions, logs, **covers** (`var/covers/`), rate limiter state |

Backup example:

```bash
docker compose exec db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" bookspot' > bookspot.sql
docker compose run --rm --no-deps app tar czf - -C /var/www/html var/covers > covers.tar.gz
```

## HTTPS

Camera, service worker, push and secure cookies need HTTPS. Put a reverse proxy in front of the
`web` container, e.g. Caddy:

```caddyfile
books.example.org {
    reverse_proxy localhost:8080
}
```

Set `APP_URL=https://books.example.org`. Cookies get the `Secure` flag automatically when the request
arrives via HTTPS (`SESSION_SECURE=auto`); if your proxy terminates TLS and forwards plain HTTP, set
`SESSION_SECURE=true`.

Behind the proxy every request seems to come from the proxy. Tell BookSpot which addresses are proxies,
so login lockout and request limits apply per visitor and not to everybody at once:

```dotenv
HTTP_PORT=127.0.0.1:8080          # only the proxy on this machine can reach the container
TRUSTED_PROXIES=172.16.0.0/12     # Docker's networks: the visitor's address comes from X-Forwarded-For
```

Only trust the proxy range when the container port is not reachable directly – otherwise anybody could
claim any address in `X-Forwarded-For`.

## Updating

```bash
git pull
docker compose up -d --build
```

Migrations run automatically on the first request. Users then tap “Reload” in the app's update banner.

## Development with live reload

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build
# PHP/nginx: http://localhost:8080 · Vite with hot reload: http://localhost:5173
```

The override mounts the source code into the containers, enables `APP_ENV=development` and
self-registration, and adds a Vite container. More in [development.md](development.md).
