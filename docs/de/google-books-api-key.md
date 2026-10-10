# Google Books API-Key anlegen (kostenlos)

[English](../google-books-api-key.md) · **Deutsch** · [← README](../../README.de.md)

SchmökerSpot fragt Buchdaten in dieser Reihenfolge ab; spätere Quellen füllen nur noch leere Felder:

1. **Open Library** – Cover, oft nur Titel und Verlag.
2. **Deutsche Nationalbibliothek (DNB)** – kostenlos, ohne Key, sehr vollständig für
   deutschsprachige Ausgaben (Autor, Seitenzahl, Jahr, Verlag, Schlagworte, Klappentext).
   Abschaltbar mit `METADATA_DNB=false`.
3. **Google Books** – für alles, was dann noch fehlt (v. a. englische Titel und Beschreibungen).

Google Books ist damit optional, aber für fremdsprachige Bücher hilfreich.

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
     - Auf Shared Hosting (z. B. [Lima-City](https://www.lima-city.de/?cref=253166) *(Reflink¹)*) kann sich die ausgehende IP ändern – dann *Keine*
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
| `200` mit `"totalItems": 0` | Key funktioniert, Google kennt diese Ausgabe nur nicht (bei deutschen Taschenbüchern häufig – dann helfen Open Library und DNB) |

## Wie viele Anfragen braucht SchmökerSpot?

- **Scannen / Anlegen**: höchstens 1 Anfrage pro Buch – nur wenn Open Library und DNB nicht
  alle Felder liefern.
- **Metadaten ergänzen** nach einem CSV-Import: höchstens 1 Anfrage pro Buch. Eine Sammlung mit
  500 Büchern passt also in das Tageskontingent.
- **Cron**: ergänzt höchstens `CRON_ENRICH_BATCH` (Standard 20) Bücher pro Lauf.
- **Stapel-Scan**: jede neue ISBN wird einmal über die Warteschlange abgefragt.
- Alle Quellen sind gedrosselt (Google Books standardmäßig 5 Anfragen pro Sekunde,
  `RATE_LIMIT_GOOGLE_BOOKS`); antwortet Google mit 429, pausiert SchmökerSpot die Quelle
  (Retry-After bzw. 1, 2, 4 … 60 Minuten) und nutzt so lange die anderen.

Ist eine Quelle nicht erreichbar oder das Kontingent erschöpft, übernimmt SchmökerSpot die Daten
der übrigen Quellen und versucht die fehlenden Felder später erneut (alle 6 Stunden, maximal 3 Versuche).
Bereits vorhandene Angaben werden nie überschrieben.

## Sicherheit

- Der Schlüssel steht nur in der `.env` auf dem Server und wird nie an den Browser ausgeliefert.
- `.env` nicht ins Git-Repository einchecken (ist in `.gitignore` eingetragen).
- Bei Verdacht auf Missbrauch: Schlüssel in der Console unter *Anmeldedaten* neu generieren.

---

¹ *Reflink: Wenn du dich über diesen Link bei Lima-City anmeldest, erhält der Projektbetreiber ggf.
eine Provision.*
