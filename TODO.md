# TODO

Done since: "Leihe" is imported as a loan – no distinction between library and private loans
needed (1.8.0); wishlist, private or shared with the instance / by public link (1.9.0);
Amazon as optional cover source, off by default (`COVER_AMAZON`, 1.9.1).

Open items from user feedback (October 2026). Questions marked ❓ need an answer before building.

## Fixes / improvements

- [ ] **Project review** (backend, frontend, ops/docs): look for bugs, odd behaviour and things to
  improve. Started once, interrupted.
- [ ] **Change the type of several books at once** in the multi-select of the book list
  (own / library loan / borrowed). Alternative to the re-import option "take over loans".

## Waiting for information

- [ ] ❓ **Bookstats CSV sample** (header + 2–3 rows, anonymised): shows whether Bookstats exports
  cover links or return dates that could be imported. (Re-reads: not in the export.)
- [ ] ❓ **PHP 8.5 host**: is `error_log` listed under `disable_functions` in `phpinfo()`? Confirms
  the cause of the "server_error" fixed in 1.7.6.
