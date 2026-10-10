# Scanning & batch scanning

[← README](../README.md)

The scanner reads the **EAN-13 barcode on the back cover** – for books that is the ISBN-13 (starting
with 978 or 979). Other barcodes are ignored.

## How the scanner works

| | |
|---|---|
| **Engine** | The browser's native `BarcodeDetector` where it supports EAN-13 (Chrome on Android and macOS, recent Safari). Everywhere else – e.g. Chrome on Windows, Firefox – the [ZXing](https://github.com/zxing-js/browser) library is loaded on demand. The engine in use is shown below the camera. |
| **Camera** | The rear camera is used by default. With several cameras a switch button (and a list for 3+) appears; the choice is remembered per device. A torch button appears when the camera supports it. |
| **Requirements** | HTTPS (or `localhost`) and the camera permission. Without a camera you can always type the ISBN. |
| **Tip** | Hold the barcode horizontally inside the frame. ZXing does not detect barcodes rotated by 90°; the native detector does. |

## Single mode

For “Do I have this?” and adding one book:

1. Scan (or type) the ISBN.
2. **Already in the catalogue** → the book page opens and shows where it is.
3. **Not in the catalogue** → the form opens with title, author, publisher, pages, genre, cover and
   blurb filled in from the [book data sources](metadata.md). Choose the type (own book, library loan,
   borrowed from a person), the location, your reading status – save.

## Batch scanning

<img src="images/bulk-scan-en.gif" width="300" align="right" alt="Batch scanning">

For the first inventory or a box of new books: switch to **Batch** at the top of the scan page.

1. **Choose the compartment** all scans go to (shelf + compartment). It is remembered; *Change* switches it at any time.
2. **Scan one book after the other.** The camera keeps running. Every scan gives a short tone and
   vibration and a green banner with the ISBN. Sound can be switched off.
3. The same ISBN twice in one session is caught (amber banner, warning tone) – no duplicates.
4. Below the camera a **live list** shows the state of every scan:

| State | Meaning |
|---|---|
| ⏳ *Loading book data …* | New book, created as a placeholder in the compartment; title etc. are being looked up |
| ✅ *New in Living room › M2* | Book data found |
| ⚠️ *Already in the catalogue – moved: R6 → M2* | The book existed elsewhere and was moved (option *Move books already in the catalogue to this compartment*) |
| ⚠️ *Was already in this compartment* | Nothing to do |
| ⚠️ *Already in the catalogue – not moved* | Option switched off, or the book is a loan |
| ❗ *No book data found – Fill in* | None of the sources knows this ISBN – complete it by hand |
| 📴 *Saved offline* | No connection – sent automatically later |

The counter in the camera picture (“12 scanned · 4 queued”) tells you at a glance how much is still
being processed. *Remove finished* clears the list; the books of course stay in the catalogue.

<br clear="right">

### What happens behind the scenes

```
scan ─► IndexedDB (local, immediately) ─► POST /api/v1/books/batch (when online, up to 50 at once)
                                              │  new ISBN: placeholder book in the compartment
                                              │  known ISBN: report / move
                                              ▼
                                       metadata_queue ─► rate-limited lookups
                                              ▲              (Open Library, DNB, Google Books)
       app polls /books/batch/process ────────┘  + cron job when the app is closed
```

- Scans are **stored on the device first**, so a weak Wi-Fi in the basement doesn't lose anything.
  Unsent scans are uploaded when the connection returns, when the app starts or comes to the
  foreground.
- The server creates the placeholder at once (title = ISBN) and **queues the lookup**. While the
  scanner page is open, the app asks the server every few seconds to work off the queue and shows
  the titles as they arrive. When you close the app, the [cron job](installation.md#7-cron-job)
  continues.
- All lookups go through the [rate limiter](metadata.md#rate-limits): 50+ scans in a row never flood
  the sources with requests or trigger their rate limits. If a source is paused, the others are used.
- Books still waiting show *Loading book data …* everywhere in the app (list, detail page).

## Typing an ISBN

The field below the camera accepts ISBN-10 and ISBN-13, with or without hyphens. In batch mode the
typed ISBN goes into the batch like a scan.

## Troubleshooting

- **Black picture / “No camera available”**: check the camera permission for the site, make sure no
  other app uses the camera, and that the page is served via HTTPS.
- **Nothing is recognised**: more light (torch button), hold the book 15–25 cm away, barcode
  horizontal and inside the frame.
- **Wrong camera on the phone**: use the switch button – the choice is remembered.
