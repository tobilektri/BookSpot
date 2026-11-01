# TODO

Done since: "Leihe" is imported as a loan – no distinction between library and private loans
needed (1.8.0); wishlist, private or shared with the instance / by public link (1.9.0);
Amazon as optional cover source, off by default (`COVER_AMAZON`, 1.9.1); project review
(November 2026) – priority 1 (wishlist privacy, data loss) and priority 2 (outgoing requests,
client IP behind proxies, Docker passwords, login lockout) fixed in 1.10.0.

Questions marked ❓ need an answer before building.

## Review, priority 3 – stability and performance (planned)

- [ ] **Cron time budget and lock** – the phases (queue 50 s, metadata 60 s, DNB titles 30 s,
  covers 60 s) can add up to ~200 s, the web cron allows 120 s and external cron services often
  give up after 30 s; a killed run skips the clean-up at the end, and overlapping runs repeat the
  same lookups.
  *Plan:* one deadline for the whole run (default 100 s, `CRON_MAX_SECONDS`) handed to every phase;
  `flock` on `var/cron.lock` (a second run exits at once); rotate which phase goes first; web cron:
  `ignore_user_abort(true)` and answer before the work starts.
- [ ] **Too many queries on wishlists and bulk-scan polling** – `BookService::get()` runs ~6 queries
  per book; a few shared lists mean >1000 queries.
  *Plan:* `BookService::getMany(ids, userId)` with one SELECT plus batched wishes/reads/readers,
  used by `WishlistService::items` and `BatchImporter::status`.
- [ ] **More than 5000 books** – the app asks for 10 000, the server caps at 5000: the rest is
  silently missing. *Plan:* `loadCatalog` pages with `limit/offset` until `total`.
- [ ] **Camera stays on / late navigation** – a scanner start error after the camera permission
  leaves the stream running; a slow ISBN lookup or the metadata polling can still navigate after
  leaving the page. *Plan:* `scanner.js` stops the tracks on any start error; `unmounted` flags in
  `ScanView`/`BookDetailView`; one lookup at a time.
- [ ] **Book view** – tapping a status or star discards unsaved notes/pages; lend, extend and
  return look like they work offline and lose the input.
  *Plan:* save status/star together with dirty form fields; disable these actions offline and only
  close the dialog on success.
- [ ] **CSV round trip** – the export protects cells starting with `= + - @` with a `'`, the import
  keeps it ("'- Tipp von Ben"). *Plan:* the importer strips one leading `'` before those characters.
- [ ] **Default loan period** – 28 days in the book form, 21 in import, wishlist and docs.
  *Plan:* one value (21) everywhere.

## Review, small items (planned)

- Docker: dev override does not reload PHP (opcache `validate_timestamps=0`) and hides `vendor/`;
  backup commands need `-T` and `$MARIADB_DATABASE`; `.dockerignore` misses `tools/` and
  `docs/images`; compose comment says "hourly" (it is every 10 minutes); CI never builds the images.
- Release bundle: add `docs/`, `README.de.md`, `composer.lock`; `contents: write` only for tags;
  `vendor/.htaccess` (deny) for installs whose document root is the project folder.
- `composer.json`: `config.platform.php = 8.2.0`.
- Docs: `VAPID_SUBJECT` really defaults to `APP_URL` (leave it empty in `.env.example`/compose);
  document `SESSION_USE_VAR_DIR`, the caps of the cron batch settings, `DB_NAME`/`DB_USER`
  defaults; German install guide misses the "old files can stay" note.
- Accessibility: dialogs without focus handling; icon buttons without names (shelf link,
  selection checkboxes, labels hidden on phones); hard-coded "Close", "Main", "Offline",
  placeholder texts.
- Import toast counts "linked" without "updated"; book form opens empty when loading the book
  fails; `renew=false` is read as true; the public wishlist cover route has no request limit;
  `settings` rows are replaced without a transaction (rare duplicate-key error with parallel runs).

## Ideas

- [ ] **Change the type of several books at once** in the multi-select of the book list
  (own / library loan / borrowed). Not needed for the Bookstats import any more.

## Waiting for information

- [ ] ❓ **Bookstats CSV sample** (header + 2–3 rows, anonymised): shows whether Bookstats exports
  cover links or return dates that could be imported. (Re-reads: not in the export.)
- [ ] ❓ **PHP 8.5 host**: is `error_log` listed under `disable_functions` in `phpinfo()`? Confirms
  the cause of the "server_error" fixed in 1.7.6.
