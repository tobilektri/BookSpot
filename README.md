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
  **Open Library**, ergänzt durch die **Deutsche Nationalbibliothek** und **Google Books**.
- **Asymmetrische Regale**: Regal → Spalten → beliebig viele Fächer pro Spalte, Fächer mit Namen
  (z. B. `L2`). Visuelle Regalansicht mit Buchrücken, Drag & Drop, Mehrfachauswahl „Verschieben nach…“.
- **Bookstats-CSV-Import** (auch Goodreads/generische CSVs): Duplikaterkennung per ISBN,
  ISBN-Normalisierung, Bereinigung unsinniger Amazon-„Genres“, Standort-Zuordnung über Fachnamen
  – Standort-Codes wie `L2`, `M4`, `R3` aus der Spalte *Standort* oder *Notizen* werden erkannt und
  können **automatisch als Regal angelegt** werden. Probelauf-Modus, Import-Protokoll.
- **Ausleihen**: Bibliotheks-Ausleihen (Frist), privat geliehene Bücher, eigene Bücher verleihen.
  Unter *Einstellungen → Meine Bibliothek* lassen sich Bibliotheksname und Standard-Leihfrist pro Nutzer
  hinterlegen: Man trägt nur das Ausleihdatum ein, die Rückgabefrist wird berechnet (auch „Verlängern“
  rechnet mit der Leihfrist).
  Dashboard-Widget „Anstehende Rückgaben“ (Hervorhebung ≤ 3 Tage), **Web-Push** 3 Tage und 1 Tag
  vorher sowie am Fälligkeitstag.
- **Statistik**: Lesestatus (Donut), gelesene Seiten, Genres (gelesen/offen), Bücher bzw. Seiten pro
  Monat und Jahr, Lesetempo, Top-Autor:innen, Bestandswert.
- **Offline-fähig**: Service Worker (App-Shell cache-first, API network-first), Katalog in
  IndexedDB (Dexie). Statusänderungen und Verschiebungen offline werden in einer Warteschlange
  gespeichert und bei Verbindung automatisch über `api/v1/sync` übertragen (Konflikterkennung).
- **Export** als Bookstats-kompatible CSV (re-importierbar).
- **Cover werden lokal gespeichert:** Der Server lädt jedes Cover einmal herunter (Cover-URL des
  Buchs, sonst Open Library per ISBN), verkleinert es auf max. 600 px (GD) und legt es in
  `var/covers/` ab; ausgeliefert wird über `api/v1/books/{id}/cover` mit Browser-Cache. Kein Hotlinking
  (CSP-konform, offline verfügbar, unabhängig von Rate-Limits). Der Cronjob lädt Cover vorab.
  **`var/covers/` gehört mit ins Backup.**
- **Einführung (Wizard)** beim ersten Login – für Benutzer und (erweitert) für Admins, jederzeit
  über die Einstellungen erneut startbar.
- Metadaten-Anreicherung (Cover, Beschreibung, Verlag, Genre) im Hintergrund in kleinen Batches.
  Google Books ist optional und benötigt einen eigenen, kostenlosen API-Key → [docs/google-books-api-key.md](docs/google-books-api-key.md).

## Architektur

```
backend/            PHP 8.2+ (Vanilla PDO, kein Framework)
  src/              Services: Auth, BookService, ShelfService, Import/, StatsService, PushService …
  src/Http/         Router, Middleware (Auth, CSRF, CORS, Rate-Limit, Fehler), Kernel = Routen
  src/Controller/   API-Controller
  migrations/       Schema für MariaDB/MySQL und SQLite (werden automatisch angewendet)
  bin/              CLI: migrate.php, cron.php, create-user.php, vapid.php
public/             Document Root
  api/index.php     Einstiegspunkt der API (/api/v1/…); die übrigen api/*.php sind
                    Weiterleitungen für ältere App-Versionen und bestehende Cron-URLs
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
3. **Domain/Subdomain auf den Projektordner oder auf `public/` zeigen lassen** (bei Lima-City:
   Domain → *Ziel/Verzeichnis*). Beides funktioniert:
   - Projektordner (z. B. `bookspot/`): Die mitgelieferte `.htaccess` leitet alle Anfragen intern
     nach `public/` um und sperrt `backend/`, `vendor/`, `var/`, `.env` und Konfigurationsdateien
     (403). Nötig: Apache mit `mod_rewrite` und `.htaccess`-Unterstützung.
   - `public/`: Dann ist der Rest des Projekts gar nicht erst erreichbar.
4. **Datenbank anlegen** (Zeichensatz `utf8mb4`) und `.env.example` als `.env` kopieren:
   `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` und `APP_URL` eintragen.
   Für vollständige Buchdaten zusätzlich einen kostenlosen `GOOGLE_BOOKS_API_KEY`
   ([Anleitung](docs/google-books-api-key.md)).
   Ohne MySQL geht auch `DB_DRIVER=sqlite`.
5. Ordner `var/` beschreibbar machen (Sessions, Logs, gespeicherte Cover, SQLite).
6. Seite aufrufen → **erstes Konto anlegen** (wird Admin). Die Tabellen werden automatisch erstellt.
   Weitere Konten legen Admins unter *Einstellungen → Benutzerverwaltung* an – mit der Rolle
   **Admin** (darf Benutzer verwalten) oder **Benutzer** (darf es nicht). Auf Wunsch geht eine
   **Einladungs-E-Mail** (PHP `mail()`, Absender `MAIL_FROM`) mit einem Link raus, über den die
   Person ihr Passwort selbst festlegt; klappt der Versand nicht, zeigt die App den Link zum
   Weitergeben. Admins können sich zur Hilfe **als ein anderer Benutzer anmelden** (violette
   Leiste „Zurück zu …“). Optional:
   Selbst-Registrierung mit `ALLOW_REGISTRATION=true`, oder per CLI `php backend/bin/create-user.php`.
7. **Cronjob** (stündlich, für Push-Erinnerungen und Metadaten): Die fertige URL mit Token steht
   für Admins unter *Einstellungen → Cronjob* (der Token wird automatisch erzeugt; ein fester
   Token kann als `CRON_TOKEN` in der `.env` gesetzt werden). Aufrufen lassen über die
   Cronjob-Funktion des Hosters oder einen externen Dienst wie cron-job.org. Mehrfachaufrufe sind
   unkritisch, jede Erinnerung wird nur einmal verschickt.

`public/.user.ini` setzt Upload-Limit (16 MB) und Laufzeit für den CSV-Import.

> **HTTPS ist Pflicht** für Kamera-Zugriff, Service Worker und Push (außer auf `localhost`).

### Fehlersuche

- **„App installieren“ wird nicht angeboten / Icons liefern 404:** Bis Version 1.0 lagen die Icons
  unter `/icons/`. Viele Apache-Server leiten `/icons/` global auf ihren eigenen Symbol-Ordner um
  (`Alias /icons/` – greift noch vor jeder `.htaccess`), dann fehlen die App-Icons, Chrome bietet
  keine Installation an und der Service Worker startet nicht. Seit Version 1.1 liegen die Icons
  unter `/app-icons/` – nach einem Update den alten Ordner `public/icons` einfach löschen.
- **404 „The requested URL was not found“, wenn die Domain auf `public/` zeigt:** Bis Version 1.1
  galten die Weiterleitungsregeln aus der `.htaccess` des Projektordners auch innerhalb von
  `public/` (Apache liest die `.htaccess` aller übergeordneten Ordner, wenn der Hoster das erlaubt)
  und zeigten auf `public/public/…`. Seit Version 1.1.1 hat `public/.htaccess` einen eigenen
  Rewrite-Kontext; Projektordner, `public/` und Unterordner-Installationen funktionieren.
- **Nach dem Leeren der Datenbank** werden die Tabellen beim nächsten Aufruf automatisch neu
  angelegt und die Ersteinrichtung erscheint wieder.

## Docker

```bash
cp .env.example .env          # DB_PASS, DB_ROOT_PASS, APP_URL anpassen
docker compose up -d --build
# → http://localhost:8080
```

Container: `web` (nginx, statisches Frontend + FastCGI nur für `api/*.php`, `/api/v1/…` → `api/index.php`), `app`
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

Alle Aufrufe laufen über einen zentralen Einstiegspunkt (`public/api/index.php`, Routen-Tabelle
in `backend/src/Http/Kernel.php`). `/api/v1/…` wird per `.htaccess` bzw. nginx dorthin
umgeleitet; ohne URL-Rewriting funktioniert dieselbe Route als `api/index.php/v1/…`.

| Route | Beschreibung |
|---|---|
| `GET /api/v1/auth/status` | Sitzung, App-Infos (öffentlich) |
| `POST /api/v1/auth/login` · `register` · `logout` · `onboarded` · `impersonation/stop` | Anmeldung |
| `GET/PUT /api/v1/auth/profile` | Profil, Sprache, Passwort, Bibliothek/Leihfrist |
| `GET /api/v1/auth/invites/{token}` · `POST /api/v1/auth/invites/accept` | Einladungslink |
| `GET/POST /api/v1/books` · `GET/PUT/DELETE /api/v1/books/{id}` | Bücher (Liste mit `q`, `sort`, `shelf_id` …) |
| `GET /api/v1/books/isbn/{isbn}` | Katalog-Suche per ISBN (`{found, book}`) |
| `GET /api/v1/books/due` · `genres` · `enrich-status` · `POST …/enrich` · `POST …/move` | Ausleihen, Genres, Metadaten, Verschieben |
| `GET/PUT /api/v1/books/{id}/personal` | persönliche Lesedaten |
| `GET /api/v1/books/{id}/cover` · `GET /api/v1/covers/preview?url=\|isbn=` | Cover (lokal gespeichert) / Vorschau |
| `GET/POST /api/v1/shelves` · `GET/PUT/DELETE /api/v1/shelves/{id}` | Regale (`columns: [{label, rows: ["L1", …]}]` oder `[4, 2, 5]`) |
| `GET/POST /api/v1/users` · `PUT/DELETE /api/v1/users/{id}` · `POST …/{id}/invite` · `…/{id}/impersonate` | Benutzerverwaltung (Ändern nur Admins) |
| `GET /api/v1/admin/system` · `POST /api/v1/admin/cron-token` | Admin: Cron-Token, Systeminfo |
| `POST /api/v1/import` · `GET /api/v1/export` | CSV-Import (multipart) / Export |
| `GET /api/v1/lookup/{isbn}` | Metadaten von Open Library, DNB und Google Books |
| `GET /api/v1/stats?year=` | Statistik |
| `POST /api/v1/sync` | Offline-Warteschlange abspielen |
| `GET /api/v1/push` · `POST/DELETE /api/v1/push/subscriptions` · `POST /api/v1/push/test` | Push |
| `GET /api/v1/cron?token=` | Webcron (Token siehe Einstellungen) |

- Schreibende Requests brauchen `X-Requested-With: BookSpot` (CSRF-Schutz) und eine gültige
  Session. PUT/PATCH/DELETE dürfen per `POST` mit `X-HTTP-Method-Override` getunnelt werden.
- Fehler kommen einheitlich als JSON `{"error": "<code>", …}`; bei zu vielen Anfragen `429` mit
  `Retry-After` (pro IP `RATE_LIMIT_REQUESTS_PER_MINUTE`, dazu Limits für Login, Lookup, Import …).
- CORS ist aus (nur gleiche Herkunft); andere Frontends lassen sich über `CORS_ALLOWED_ORIGINS` freigeben.
- Die alten Endpunkte `api/books.php`, `api/cron.php?token=` … funktionieren weiter (gleiche
  Controller); bestehende Cron-URLs müssen nicht geändert werden.

## Lizenz

[MIT](LICENSE)
