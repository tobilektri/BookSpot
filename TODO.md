# TODO

Done since: "Leihe" is imported as a loan – no distinction between library and private loans
needed (1.8.0); wishlist, private or shared with the instance / by public link (1.9.0);
Amazon as optional cover source, off by default (`COVER_AMAZON`, 1.9.1); project review
(November 2026) – priority 1 (wishlist privacy, data loss) and priority 2 (outgoing requests,
client IP behind proxies, Docker passwords, login lockout) fixed in 1.10.0, priority 3
(cron time budget and lock, batched book loading, large collections, scanner/book view
robustness, CSV round trip, one loan period, accessibility, Docker/CI/docs) in 1.13.0.

Questions marked ❓ need an answer before building.

## Ideas

- [ ] **Change the type of several books at once** in the multi-select of the book list
  (own / library loan / borrowed). Not needed for the Bookstats import any more.

## Waiting for information

- [ ] ❓ **Bookstats CSV sample** (header + 2–3 rows, anonymised): shows whether Bookstats exports
  cover links or return dates that could be imported. (Re-reads: not in the export.)
- [ ] ❓ **PHP 8.5 host**: is `error_log` listed under `disable_functions` in `phpinfo()`? Confirms
  the cause of the "server_error" fixed in 1.7.6.
