# TODO

Open items from user feedback (October 2026). Questions marked ❓ need an answer before building.

## Fixes / improvements

- [ ] **Project review** (backend, frontend, ops/docs): look for bugs, odd behaviour and things to
  improve. Started once, interrupted.
- [ ] **CSV import, meaning of "Leihe"**: since 1.8.0 Bookstats' `Erhalten als: Leihe` is imported as
  a *library* loan (Onleihe case).
  ❓ Does "Leihe" in Bookstats also cover books borrowed from friends? If so, the import needs a
  choice (library / person) or a question during the import.
- [ ] **Change the type of several books at once** in the multi-select of the book list
  (own / library loan / borrowed). Alternative to the re-import option "take over loans".

## Features (not promised)

- [ ] **Wishlist**: own type that does not appear in "All", unread/reading/read or the statistics,
  only via its own filter; "bought" / "borrowed" turns it into an own book or a loan.
  ❓ Visible only to the person who added it, or to everybody in the instance (gift ideas)?
  Suggestion: personal, with "visible to others" as an option.
- [ ] **Amazon as cover source** (optional, off by default, ISBN-10 image URL).
  ❓ Wanted at all? Amazon only allows its images for partners (legal grey zone).

## Waiting for information

- [ ] ❓ **Bookstats CSV sample** (header + 2–3 rows, anonymised): shows whether Bookstats exports
  cover links or return dates that could be imported. (Re-reads: not in the export.)
- [ ] ❓ **PHP 8.5 host**: is `error_log` listed under `disable_functions` in `phpinfo()`? Confirms
  the cause of the "server_error" fixed in 1.7.6.
