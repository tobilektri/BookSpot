# Book data & covers

[← README](../README.md)

## Sources

When you scan or type an ISBN, the **server** (never the browser) asks these sources in order. Later
sources only fill fields that are still empty:

| # | Source | Key needed | Strengths |
|---|---|---|---|
| 1 | [Open Library](https://openlibrary.org) | no | covers, international editions |
| 2 | [German National Library (DNB)](https://www.dnb.de) | no | very complete for German-language books: authors, pages, year, publisher, subjects, the publisher's blurb |
| 3 | [Google Books](https://books.google.com) | **yes**, free – [how to](google-books-api-key.md) | descriptions and English titles |

The lookup stops early once title, authors, pages, year, publisher, genre, cover and description are
known. Data you entered yourself is never overwritten.

DNB records follow library cataloguing rules, which BookSpot tidies up: ISBD punctuation is removed,
the spelled-out form of numbers that is only there for sorting is dropped (“Die große 5[Fünf]” →
“Die große 5”), volumes of multi-part works get their part title (“Die fünf Temponauten. Treffpunkt
2321”) and abbreviated publishers are expanded where it is unambiguous (“Bergverl.” → “Bergverlag”).

**Titles saved by versions before 1.7.3** are corrected once after the update: migration 010 marks
every book that exists at that moment, and the cron job looks them up at the DNB again, 30 per run
(2 requests per second). A title is only replaced if it is still exactly what the old version
made of the DNB record – titles from other sources or edited by hand stay as they are. When all books
are done the marker is removed; books added later are never looked up for this. To do it in one go:
`php backend/bin/repair-dnb-titles.php`.

Switches: `METADATA_LOOKUP=false` (no online lookups at all), `METADATA_DNB=false`, no
`GOOGLE_BOOKS_API_KEY` = Google is skipped (it would only answer 429).

## When lookups happen

| Situation | How |
|---|---|
| Adding a single book | immediately, the form is filled in while you look at it |
| [Batch scanning](scanning.md#batch-scanning) | asynchronously via the **metadata queue**: placeholders first, data shortly after |
| After a CSV import / books with gaps | “Fill in now” in the app or the cron job, in small batches (`CRON_ENRICH_BATCH`) |

**Partial results**: if a source is down while the others answered, the book gets what is available
and is asked again later – after 6 h, 12 h, 1 day, 2, 4, 7 and 14 days (8 attempts in total). This
matters for Google Books, which frequently answers `503 backendFailed`. “Not found” only becomes
final when every source could actually be asked.

**Books without cover** are never considered done: as long as no source had a cover, they are looked
up again once a month (sources add covers over time).

**Google Books matching**: an `isbn:` search at Google is a full-text search and also returns
catalogues or books that merely mention the ISBN – often as the first hit. BookSpot only accepts a
volume whose ISBN list contains the exact ISBN.

### The metadata queue (batch scanning)

- New ISBNs from a batch become placeholder books (`metadata_status = pending`) and an entry in `metadata_queue`.
- The queue is worked off by the app while the scanner is open (`POST /api/v1/books/batch/process`)
  and by the cron job (`CRON_QUEUE_BATCH` per run).
- Entries are claimed atomically, so parallel runs (two phones, app + cron) never process the same book twice.
- Unreachable sources: the entry is retried after 1, 5, 15, 60, 360 minutes; after 8 attempts it is
  marked *not found* and the app asks you to fill it in.

## Rate limits

Bulk work must not get your server blocked. Every request to an external source passes a
**rate limiter** shared by all PHP processes (state in `var/ratelimit/`, protected by file locks –
no daemon or cache server needed):

| Source | Default | Setting |
|---|---|---|
| Open Library API | 1 request/s, **3 with `CONTACT_EMAIL`** ([their limits](https://openlibrary.org/developers/api)) | `RATE_LIMIT_OPEN_LIBRARY` |
| German National Library | 2 requests/s | `RATE_LIMIT_DNB` |
| Google Books | 5 requests/s | `RATE_LIMIT_GOOGLE_BOOKS` |
| Open Library covers by ISBN | 1 every 3 s (Open Library allows 100 per 5 minutes and IP) | `RATE_LIMIT_OPEN_LIBRARY_COVERS` |
| DNB covers | 2 requests/s | `RATE_LIMIT_DNB_COVERS` |

- A request **waits** briefly for its slot (a few seconds at most); if the wait would be longer, the
  source is skipped for now and the next one is used.
- If a source answers **HTTP 429**, it is **paused**: for the time in its `Retry-After` header,
  otherwise 1, 2, 4 … up to 60 minutes for repeated 429s. During the pause BookSpot doesn't ask it at
  all and falls back to the other sources; the first successful answer resets the backoff.

Tested in `tests/RateLimiterTest.php` and `tests/BatchImporterTest.php` (60 scans in a row without a
single 429 from simulated strict sources).

## Covers

Covers are **downloaded once by the server and stored locally** in `var/covers/` – include that
folder in your backups.

- Sources, in order: the book's cover URL (from the lookup or entered by hand), then by ISBN
  [Open Library](https://openlibrary.org/dev/docs/api/covers) and the German National Library's cover
  service (`portal.dnb.de/opac/mvb/cover?isbn=…`, publisher covers from the VLB / buchhandel.de).
  For German ISBNs (978-3…) the DNB is asked first – it has them far more often and it spares Open
  Library's tight limit. Switch it off with `COVER_DNB=false`.
- Images are validated (real JPEG/PNG/WebP/GIF, not the 1×1 placeholder some services return) and
  scaled down to 600 px height when GD is available.
- They are served by `GET /api/v1/books/{id}/cover` with long-lived browser caching and an ETag; the
  service worker keeps them for offline use.
- Not found → the app draws a coloured placeholder cover with title and author. Temporary errors are
  retried after an hour; “no cover anywhere” after 30 days.
- The cron job downloads covers ahead of time (`CRON_COVER_BATCH`), so lists load fast.

### Privacy and security

- The browser **never loads images from other hosts** – the Content Security Policy only allows
  `img-src 'self'`. Even the preview in the book form is fetched through the server.
- Because the server fetches URLs that come from external data or user input, it only connects to
  **public internet addresses**: `localhost`, private networks (`10.x`, `192.168.x` …), link-local
  addresses (cloud metadata endpoints) and non-HTTP schemes are refused, the resolved IP is pinned for
  the connection, and every redirect is checked again. Set `COVER_ALLOW_PRIVATE_HOSTS=true` only if
  your cover images really live in your own network.
