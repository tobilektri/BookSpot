# Installation auf Webspace / Shared Hosting

[English](../installation.md) · **Deutsch** · [← README](../../README.de.md)

SchmökerSpot braucht weder SSH noch Node.js noch einen Hintergrunddienst. Webspace mit PHP, eine
Datenbank und FTP genügen. Diese Anleitung ist am Beispiel von
[Lima-City](https://www.lima-city.de/?cref=253166) *(Reflink¹)* entstanden, funktioniert aber bei
jedem Apache-basierten Hoster genauso.

## Voraussetzungen

| | Pflicht | Empfohlen |
|---|---|---|
| PHP | 8.2 oder neuer | 8.5 |
| PHP-Erweiterungen | `pdo_mysql` (oder `pdo_sqlite`), `mbstring`, `json`, `openssl` | `curl` (schnellere Abfragen), `gd` (verkleinert Cover), `dom` (Deutsche Nationalbibliothek), `gmp` (schnellere Push-Verschlüsselung) |
| Datenbank | MySQL 8 / MariaDB 10.6+ mit `utf8mb4` – oder SQLite (ganz ohne Datenbankserver) | MariaDB (die CI-Tests laufen auf MariaDB 11.4) |
| Webserver | Apache mit `mod_rewrite` und `.htaccess` (`AllowOverride All`) | – |
| HTTPS | **Pflicht** für Kamera, Service Worker, Push und sichere Cookies | kostenloses Let's-Encrypt-Zertifikat |

Bei den meisten Hostern lässt sich die PHP-Version pro Domain in der Verwaltungsoberfläche wählen.

## 1. Paket holen

**`bookspot.zip`** aus den [Releases](https://github.com/tobilektri/BookSpot/releases) laden. Gibt es
noch kein Release, das Artefakt **`bookspot-bundle`** des letzten erfolgreichen
[CI-Laufs](https://github.com/tobilektri/BookSpot/actions/workflows/ci.yml) nehmen. Das Paket enthält
bereits die PHP-Abhängigkeiten (`vendor/`) und die fertig gebaute App (`public/assets`, `public/sw.js` …).

Selbst bauen:

```bash
composer install --no-dev --optimize-autoloader
cd frontend && npm ci && npm run build    # schreibt nach ../public
```

## 2. Hochladen

Archiv entpacken und alles per FTP/SFTP in einen Ordner deines Webspace laden, z. B. `/bookspot`.
Die Ordnerstruktur bleibt erhalten – `backend/`, `vendor/` und `var/` liegen neben `public/`.

## 3. Domain auf die App zeigen lassen

Eine (Sub-)Domain wie `buecher.example.org` anlegen und ihr **Zielverzeichnis** setzen (bei
Lima-City: Domain → *Ziel/Verzeichnis*). Beide Varianten funktionieren:

| Document Root | So funktioniert es |
|---|---|
| **`bookspot/public/`** (empfohlen) | Nur der öffentliche Teil ist überhaupt erreichbar. |
| **`bookspot/`** (Projektordner) | Die `.htaccess` im Projektordner leitet alle Anfragen intern nach `public/` und beantwortet `backend/`, `vendor/`, `var/`, `tests/`, `.env`, `*.sql`, `*.log` … mit `403`. |

Auch eine Installation im Unterverzeichnis (`example.org/buecher/`) geht – die App nutzt relative Pfade.

## 4. Datenbank und `.env`

1. Datenbank anlegen (Zeichensatz **`utf8mb4`**, Kollation `utf8mb4_unicode_ci`) und einen Benutzer dafür.
2. `.env.example` als **`.env`** kopieren (in den Projektordner, nicht nach `public/`) und mindestens setzen:

   ```dotenv
   APP_URL=https://buecher.example.org
   DB_HOST=localhost
   DB_NAME=bookspot
   DB_USER=bookspot
   DB_PASS=dein-passwort
   ```

   Ohne MySQL-Datenbank: `DB_DRIVER=sqlite` – die Datei entsteht in `var/`.
3. Optional, aber lohnend: ein kostenloser [Google-Books-API-Key](google-books-api-key.md) für
   Buchdaten in anderen Sprachen, `MAIL_FROM` für Einladungs-E-Mails und `CONTACT_EMAIL` – wird bei
   Anfragen an die Buchdaten-Quellen mitgeschickt, Open Library erlaubt dann 3 statt 1 Anfrage pro Sekunde.

Alle Einstellungen (englisch): [configuration.md](../configuration.md).

## 5. `var/` beschreibbar machen

In `var/` liegen Sessions, Logs, die heruntergeladenen Cover (`var/covers/`), der Zustand der
Ratenbegrenzung und – bei SQLite – die Datenbank. Der Webserver braucht Schreibrechte (auf Webspace
meist schon so eingestellt; sonst im FTP-Programm `chmod 775 var`).

## 6. Erster Start

Seite aufrufen. Bei leerer Datenbank legt SchmökerSpot die Tabellen automatisch an und zeigt die
**Ersteinrichtung**: Das erste Konto wird **Admin**. Danach erklärt die Einführung die App; als Admin
kannst du direkt eine CSV importieren und den Rest des Haushalts einladen
([Benutzer & Rollen](../users-and-roles.md), englisch).

## 7. Cronjob

Eine Webcron-URL stößt an, was passieren soll, während niemand hinschaut:

- Push-Erinnerungen für fällige Ausleihen (pro Buch und Tag nur einmal – mehrfache Aufrufe schaden nicht)
- die Metadaten-Warteschlange des [Stapel-Scans](../scanning.md#batch-scanning)
- Ergänzen fehlender Buchdaten und Vorab-Download der Cover

Die vollständige URL mit Token sehen Admins unter **Einstellungen → Cronjob**, z. B.

```
https://buecher.example.org/api/v1/cron?token=…
```

Diese URL in der Cronjob-Funktion des Hosters eintragen (bei Lima-City: *Cronjobs* in der
Verwaltung) oder bei einem externen Dienst wie cron-job.org. **Alle 10–15 Minuten** ist ein guter
Takt; stündlich geht auch. Der Token wird automatisch erzeugt – wer einen festen möchte, setzt
`CRON_TOKEN` in der `.env`. Adressen älterer Versionen (`api/cron.php?token=…`) funktionieren weiter.

## 8. Push-Benachrichtigungen (optional)

Nichts zu tun: Die VAPID-Schlüssel für Web Push werden beim ersten Gebrauch erzeugt und in der
Datenbank gespeichert. Jede Person schaltet Push auf ihrem Gerät unter *Einstellungen* ein. Auf iOS
muss die App dafür zuerst zum Home-Bildschirm hinzugefügt werden.

## Aktualisieren

1. Sicherung anlegen (siehe unten).
2. Neues Paket hochladen, dabei `.env` und `var/` **nicht** überschreiben.
3. Seite aufrufen – Datenbank-Migrationen laufen automatisch.
4. In der installierten App im Update-Hinweis auf **„Neu laden“** tippen (dann wechselt der Service Worker zur neuen Version).

## Sicherung

Wichtig sind:

- die **Datenbank** (Export per phpMyAdmin/Adminer oder `var/bookspot.sqlite` kopieren),
- **`var/covers/`** – die heruntergeladenen Cover,
- deine **`.env`**.

Ein CSV-Export (*Einstellungen → Export*) ist eine schöne Ergänzung – Bookstats-kompatibel und wieder importierbar.

## Klappt etwas nicht?

[Fehlersuche](../troubleshooting.md) (englisch): Installieren-Knopf fehlt, 404 mit `public/` als
Document Root, Kamera startet nicht, Push kommt nicht an.

---

¹ *Reflink: Wenn du dich über diesen Link bei Lima-City anmeldest, erhält der Projektbetreiber ggf.
eine Provision. SchmökerSpot läuft bei jedem Hoster, der die Voraussetzungen erfüllt.*
