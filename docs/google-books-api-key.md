# Google Books API-Key anlegen (kostenlos)

SchmökerSpot fragt Buchdaten zuerst bei **Open Library** ab. Dort fehlen bei vielen deutschen
Ausgaben Autor, Seitenzahl, Erscheinungsjahr, Genre oder Beschreibung – diese Lücken füllt
**Google Books**.

## Warum ein eigener Key nötig ist

Anfragen **ohne** API-Key laufen über ein von Google geteiltes Kontingent, das inzwischen auf
`0` steht. Die Antwort ist dann immer:

```json
{ "error": { "code": 429, "status": "RESOURCE_EXHAUSTED",
  "message": "Quota exceeded for quota metric 'Queries' and limit 'Queries per day' …",
  "details": [{ "metadata": { "quota_limit": "defaultPerDayPerProject", "quota_limit_value": "0" } }] } }
```

Mit einem eigenen Key bekommt dein Projekt ein eigenes, **kostenloses Tageskontingent**
(standardmäßig 1.000 Anfragen pro Tag – den aktuellen Wert siehst du in der Cloud Console unter
*Kontingente*). Die Books API ist kostenlos; ein Rechnungskonto (Kreditkarte) ist dafür nicht
erforderlich.

## Schritt für Schritt

1. **Google Cloud Console öffnen**: <https://console.cloud.google.com/> – mit einem beliebigen
   Google-Konto anmelden und ggf. den Nutzungsbedingungen zustimmen.
2. **Projekt anlegen**: oben links auf die Projektauswahl → *Neues Projekt* → Name z. B.
   `SchmoekerSpot` → *Erstellen*. Danach sicherstellen, dass dieses Projekt ausgewählt ist.
3. **Books API aktivieren**: Menü ☰ → *APIs & Dienste* → *Bibliothek* → nach **„Books API“**
   suchen → *Aktivieren*.
   (Direktlink: <https://console.cloud.google.com/apis/library/books.googleapis.com>)
4. **API-Key erstellen**: *APIs & Dienste* → *Anmeldedaten* → *Anmeldedaten erstellen* →
   **API-Schlüssel**. Den angezeigten Schlüssel (beginnt mit `AIza…`) kopieren.
5. **Key einschränken** (dringend empfohlen) – in der Liste auf den neuen Schlüssel klicken:
   - **API-Einschränkungen** → *Schlüssel einschränken* → nur **Books API** auswählen.
   - **Anwendungseinschränkungen**: Die Abfragen kommen vom Server (PHP), nicht aus dem Browser.
     „HTTP-Verweis-URLs (Websites)“ funktioniert daher **nicht**.
     - Hat dein Server eine feste ausgehende IP (VPS, Docker-Host): **IP-Adressen** wählen und
       diese IP eintragen.
     - Auf Shared Hosting (z. B. Lima-City) kann sich die ausgehende IP ändern – dann *Keine*
       lassen und nur die API-Einschränkung nutzen.
   - *Speichern*. Änderungen können einige Minuten dauern.
6. **In SchmökerSpot eintragen** – in der `.env` im Projektordner:

   ```dotenv
   GOOGLE_BOOKS_API_KEY=AIza...dein-schluessel
   ```

   Bei Docker die Variable in der `.env` neben der `docker-compose.yml` setzen und die Container
   neu starten: `docker compose up -d`.

## Testen

Im Browser oder Terminal (Schlüssel einsetzen):

```
https://www.googleapis.com/books/v1/volumes?q=isbn:9783548283951&key=AIza...
```

Kommt ein JSON mit `"items": [...]` zurück, funktioniert der Key. In SchmökerSpot: *Buch anlegen*
→ ISBN eingeben → *Daten abrufen*, oder unter *Einstellungen → Metadaten ergänzen*.

| Antwort | Bedeutung |
|---|---|
| `429` mit `quota_limit_value: "0"` | Key fehlt oder wird nicht mitgeschickt (`.env` prüfen) |
| `429` mit Wert > 0 | Tageskontingent verbraucht – am nächsten Tag geht es weiter |
| `403` `API_KEY_SERVICE_BLOCKED` / `accessNotConfigured` | Books API im Projekt nicht aktiviert (Schritt 3) |
| `403` `API_KEY_IP_ADDRESS_BLOCKED` | IP-Einschränkung passt nicht zur Server-IP (Schritt 5) |
| `400` `API key not valid` | Schlüssel falsch kopiert |

## Wie viele Anfragen braucht SchmökerSpot?

- **Scannen / Anlegen**: 1 Anfrage pro Buch (nur wenn Open Library nicht alle Felder liefert).
- **Metadaten ergänzen** nach einem CSV-Import: höchstens 1 Anfrage pro Buch. Eine Sammlung mit
  500 Büchern passt also in das Tageskontingent.
- **Cron**: ergänzt höchstens `CRON_ENRICH_BATCH` (Standard 20) Bücher pro Lauf, bei stündlichem
  Cron also maximal 480 pro Tag.

Ist Google nicht erreichbar oder das Kontingent erschöpft, übernimmt SchmökerSpot die Daten von
Open Library und versucht die fehlenden Felder später erneut (alle 6 Stunden, maximal 3 Versuche).
Bereits vorhandene Angaben werden nie überschrieben.

## Sicherheit

- Der Schlüssel steht nur in der `.env` auf dem Server und wird nie an den Browser ausgeliefert.
- `.env` nicht ins Git-Repository einchecken (ist in `.gitignore` eingetragen).
- Bei Verdacht auf Missbrauch: Schlüssel in der Console unter *Anmeldedaten* neu generieren.
