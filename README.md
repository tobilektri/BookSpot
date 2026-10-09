# SchmökerSpot · BookSpot

Selbst gehostete, quelloffene **Progressive Web App** für deine Büchersammlung:
Barcode-Scanner, Regal- und Fächer-Organisation, Bookstats-CSV-Import,
Bibliotheks-Ausleihen mit Push-Erinnerung und Lesestatistiken.

> 🇬🇧 *BookSpot is a self-hosted PWA to manage a book collection: barcode scanning,
> shelf/compartment organisation, Bookstats CSV import, library loan reminders via
> Web Push and reading statistics. Runs on plain PHP shared hosting (FTP + MySQL)
> or with Docker. The UI is available in German and English.*

## Funktionen

- **Gemeinsamer Katalog, private Lesedaten** – alle Nutzer sehen dieselben Bücher, Regale und
  Ausleihen; Lesestatus, Bewertung, Fortschritt, Daten und Notizen sind pro Nutzer getrennt.
- **Barcode-Scan (EAN-13/ISBN)** über die Handykamera – native `BarcodeDetector`-API, ZXing als
  Fallback. Gefunden → Detailansicht mit Standort; nicht gefunden → Anlege-Dialog mit Daten aus
  **Open Library** (Fallback **Google Books**).
- **Asymmetrische Regale**: Regal → Spalten → beliebig viele Fächer pro Spalte, Fächer mit Namen
  (z. B. `L2`). Visuelle Regalansicht mit Buchrücken, Drag & Drop, Mehrfachauswahl „Verschieben nach…“.
- **Bookstats-CSV-Import** (auch Goodreads/generische CSVs): Duplikaterkennung per ISBN,
  ISBN-Normalisierung, Bereinigung unsinniger Amazon-„Genres“, Standort-Zuordnung über Fachnamen
  – Standort-Codes wie `L2`, `M4`, `R3` aus der Spalte *Standort* oder *Notizen* werden erkannt und
  können **automatisch als Regal angelegt** werden. Probelauf-Modus, Import-Protokoll.
- **Ausleihen**: Bibliotheks-Ausleihen (Frist), privat geliehene Bücher, eigene Bücher verleihen.
  Dashboard-Widget „Anstehende Rückgaben“ (Hervorhebung ≤ 3 Tage), **Web-Push** 3 Tage und 1 Tag
  vorher sowie am Fälligkeitstag.
- **Statistik**: Lesestatus (Donut), gelesene Seiten, Genres (gelesen/offen), Bücher bzw. Seiten pro
  Monat und Jahr, Lesetempo, Top-Autor:innen, Bestandswert.
- **Offline-fähig**: Service Worker (App-Shell cache-first, API network-first), Katalog in
  IndexedDB (Dexie). Statusänderungen und Verschiebungen offline werden in einer Warteschlange
  gespeichert und bei Verbindung automatisch über `api/sync.php` übertragen (Konflikterkennung).
- **Export** als Bookstats-kompatible CSV (re-importierbar).
- Metadaten-Anreicherung (Cover, Beschreibung, Verlag, Genre) im Hintergrund in kleinen Batches.

## Architektur

```
backend/            PHP 8.2+ (Vanilla PDO, kein Framework)
  src/              Services: Auth, BookService, ShelfService, Import/, StatsService, PushService …
  migrations/       Schema für MariaDB/MySQL und SQLite (werden automatisch angewendet)
  bin/              CLI: migrate.php, cron.php, create-user.php, vapid.php
public/             Document Root
  api/*.php         REST-Endpunkte (auth, books, shelves, user_books, import, export, stats,
                    lookup, push, sync, cron)
  (index.html, assets/, sw.js …)   ← von Vite gebautes Frontend
frontend/           Vue 3 + Vite + Tailwind CSS 4 + Dexie + Chart.js (Quellcode)
docker/             nginx- und PHP-Konfiguration
tests/              PHPUnit (SQLite und MariaDB)
```

- Alle Datenbankzugriffe über **PDO Prepared Statements**.
- **Sessions** mit `HttpOnly`, `SameSite=Strict`, `Secure` (bei HTTPS); Passwörter mit
  **Argon2id** (Fallback bcrypt, falls der Hoster kein Argon2 hat); Login-Drosselung pro IP.
- **CSRF-Schutz**: schreibende Requests benötigen den Header `X-Requested-With: BookSpot`
  (Cross-Site-Requests können ihn ohne CORS-Preflight nicht setzen) + SameSite=Strict.
- **XSS**: Die API liefert ausschließlich JSON (`JSON_HEX_TAG`, `nosniff`, restriktive CSP); Vue
  escaped alle Ausgaben, es wird kein `v-html` verwendet. Cover-URLs werden auf `http(s)` geprüft,
  CSV-Exporte gegen Formel-Injection abgesichert.
- Konfiguration über `.env` (siehe `.env.example`).
- Hash-Routing und relative Pfade: Die App läuft auch in Unterverzeichnissen, ohne Rewrite-Regeln.
  PUT/PATCH/DELETE werden per `X-HTTP-Method-Override` über POST getunnelt (manche Hoster blocken sie).

## Installation auf Shared Hosting (z. B. Lima-City)

Voraussetzungen: PHP ≥ 8.2 (empfohlen 8.5) mit `pdo_mysql`, `mbstring`, `openssl`, `curl`;
MySQL/MariaDB-Datenbank. Kein SSH, Node.js oder Root nötig.

1. **Paket holen**: Unter *Actions → CI → Artifacts* (`bookspot-bundle`) bzw. bei Releases liegt
   eine fertige `bookspot.zip` mit `vendor/` und gebautem Frontend. Alternativ lokal bauen:
   `composer install --no-dev && cd frontend && npm ci && npm run build`.
2. **Hochladen** (FTP) in einen Ordner, z. B. `/bookspot`.
3. **Domain/Subdomain auf den Ordner `public/` zeigen lassen** (bei Lima-City: Domain →
   *Ziel/Verzeichnis* ändern). Geht das nicht, leitet die mitgelieferte `.htaccess` im
   Projektordner auf `public/` um und sperrt `backend/`, `vendor/`, `var/` und `.env`.
4. **Datenbank anlegen** (Zeichensatz `utf8mb4`) und `.env.example` als `.env` kopieren:
   `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`, `CRON_TOKEN` eintragen.
   Ohne MySQL geht auch `DB_DRIVER=sqlite`.
5. Ordner `var/` beschreibbar machen (Sessions, Logs, SQLite).
6. Seite aufrufen → **erstes Konto anlegen** (wird Admin). Die Tabellen werden automatisch erstellt.
   Weitere Konten: `ALLOW_REGISTRATION=true` oder `php backend/bin/create-user.php`.
7. **Cronjob** (stündlich, für Push-Erinnerungen und Metadaten):
   `https://deine-domain.tld/api/cron.php?token=<CRON_TOKEN>` – über die Cronjob-Funktion des
   Hosters oder einen externen Dienst wie cron-job.org. Mehrfachaufrufe sind unkritisch, jede
   Erinnerung wird nur einmal verschickt.

`public/.user.ini` setzt Upload-Limit (16 MB) und Laufzeit für den CSV-Import.

> **HTTPS ist Pflicht** für Kamera-Zugriff, Service Worker und Push (außer auf `localhost`).

## Docker

```bash
cp .env.example .env          # DB_PASS, DB_ROOT_PASS, CRON_TOKEN, APP_URL anpassen
docker compose up -d --build
# → http://localhost:8080
```

Container: `web` (nginx, statisches Frontend + FastCGI nur für `api/*.php`), `app`
(PHP 8.5-FPM mit OPcache, GMP), `db` (MariaDB 11.4, Healthcheck), `cron` (führt
`backend/bin/cron.php` stündlich aus). Daten liegen in den Volumes `mariadb_data` und `app_var`.
Für HTTPS einen Reverse Proxy (Traefik, Caddy, nginx) davorschalten.

Entwicklung mit Live-Reload:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build
# PHP/nginx: http://localhost:8080   ·   Vite: http://localhost:5173
```

## Lokale Entwicklung ohne Docker

```bash
composer install
cp .env.example .env    # z. B. DB_DRIVER=sqlite, APP_ENV=development, ALLOW_REGISTRATION=true
php -S localhost:8000 -t public          # API (+ gebautes Frontend)
cd frontend && npm install && npm run dev # Vite auf :5173, /api wird an :8000 weitergeleitet
vendor/bin/phpunit                        # Tests (SQLite in-memory)
TEST_DB_DSN="mysql:host=127.0.0.1;dbname=bookspot_test" TEST_DB_USER=… TEST_DB_PASS=… vendor/bin/phpunit
```

## Bookstats-Import im Detail

| Bookstats-Spalte | BookSpot |
|---|---|
| Titel, Autor(en), Seitenanzahl, Erscheinungsjahr, Buchart, Preis, Erhalten am | Katalog |
| ISBN (+ ASIN, falls sie eine ISBN-10 ist; sonst als Kindle-ASIN) | `isbn13`, `isbn10`, `asin` |
| Genre (Amazon-IDs, „Taschenbücher“, „Top100“ … werden verworfen; Fallback: Kategorie) | `genre` |
| Lesestatus, Lesebeginn, Leseende, Bewertung | persönliche Daten des importierenden Nutzers |
| Standort **oder** Notizen mit Fachnamen (`L2`) | Regal/Spalte/Fach |
| übrige Notizen | persönliche Notizen |

Bereits vorhandene Bücher (gleiche ISBN, ohne ISBN: Titel + Autor) werden nicht doppelt angelegt,
nur leere Felder werden ergänzt. Mehrere Nutzer können dieselbe CSV importieren, um ihre eigenen
Lesedaten zu übernehmen. Weitere zu ignorierende Genre-Werte: `IMPORT_GENRE_BLOCKLIST` in `.env`.

## API (Kurzüberblick)

| Endpunkt | Beschreibung |
|---|---|
| `GET api/auth.php?action=status` · `POST …?action=login/register/logout/profile` | Sitzung |
| `GET api/books.php?isbn=…` | Katalog-Suche per ISBN (`{found, book}`) |
| `GET/POST/PUT/DELETE api/books.php[?id=]` | Bücher; `?action=move`, `due`, `genres`, `enrich` |
| `GET/PUT api/user_books.php?book_id=` | persönliche Lesedaten |
| `GET/POST/PUT/DELETE api/shelves.php[?id=]` | Regale (`columns: [{label, rows: ["L1", …]}]` oder `[4, 2, 5]`) |
| `POST api/import.php` · `GET api/export.php` | CSV-Import (multipart) / Export |
| `GET api/lookup.php?isbn=` | Metadaten von Open Library / Google Books |
| `GET api/stats.php?year=` | Statistik |
| `POST api/sync.php` | Offline-Warteschlange abspielen |
| `GET/POST api/push.php` | VAPID-Key, (De-)Abonnieren, Test |
| `GET api/cron.php?token=` | Erinnerungen + Metadaten (Webcron) |

Schreibende Requests brauchen `X-Requested-With: BookSpot` und eine gültige Session.

## Lizenz

[MIT](LICENSE)
