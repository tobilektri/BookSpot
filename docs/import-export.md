# Import & export

[← README](../README.md)

## CSV import

*Settings → CSV import*. Made for exports of the **Bookstats** app, but any CSV with columns
like title, author, ISBN works – e.g. a **Goodreads** export or a spreadsheet you exported yourself.

1. Choose the file (up to 15 MB; delimiter `;`, `,` or tab and the encoding are detected).
2. Leave **“Only check (dry run)”** on for the first attempt: nothing is saved, you get a report of
   what would happen – how many books are new, already there, skipped, which location codes were
   not found, which columns were recognised.
3. Options:
   - **Create unknown locations as a new shelf** – builds a shelf from codes like `L2`, `M4`
     ([how](shelves.md#location-codes-from-the-csv-import)), with the name you enter.
   - **Overwrite existing reading data** – otherwise your existing status/rating/dates win.
   - **Take the location of existing books from the CSV** – otherwise books that already have a
     place keep it.
4. Run the import. Afterwards the app offers to **fill in covers and descriptions** for the new books
   (rate-limited, in small batches – you can keep using the app meanwhile).

### Column mapping

Column names are matched case- and accent-insensitively in German and English:

| BookSpot field | Recognised columns (examples) |
|---|---|
| Title | `Titel`, `Title`, `Name` |
| Authors | `Autor(en)`, `Autor`, `Author`, `Authors` |
| ISBN | `ISBN`, `ISBN13`, `EAN` (ISBN-10 is converted; an `ASIN` that is an ISBN-10 counts as ISBN) |
| Publisher, year, pages | `Verlag`/`Publisher`, `Erscheinungsjahr`/`Year Published`, `Seitenanzahl`/`Number of Pages` |
| Genre | `Genre`, fallback `Kategorie`/`Category` – Amazon category IDs and junk like “Taschenbücher”, “Top100” are dropped (`IMPORT_GENRE_BLOCKLIST` adds more) |
| Format, price, acquired | `Buchart`/`Binding`, `Preis`/`Price`, `Erhalten am`/`Date Added` |
| Reading status, start, end, rating | `Lesestatus`/`Exclusive Shelf`, `Lesebeginn`, `Leseende`/`Date Read`, `Bewertung`/`My Rating` |
| Location | `Standort`/`Location`/`Shelf` – or a code like `L2` inside the notes |
| Notes | `Notizen`/`Notes`/`Private Notes` (minus the location code) |

Catalogue fields go into the shared catalogue; **reading status, rating, dates and notes belong to
the person who imports**. Several people can import their own CSV (or the same one) to bring in their
own reading history.

### Duplicates

- Same ISBN = same book. Without ISBN: same title + author.
- Existing books are never duplicated; only **empty fields are filled in**.
- Within one file the same book is only created once.

## CSV export

*Settings → Export* downloads the whole catalogue with **your** reading data as a Bookstats-compatible
CSV (semicolon, UTF-8 with BOM, opens directly in Excel/LibreOffice). It can be imported again – into
BookSpot or Bookstats. Cells that would start with `=`, `+`, `-` or `@` are escaped so a spreadsheet
does not execute them as formulas.

Covers are not part of the CSV; for a complete backup see [installation.md](installation.md#backups).
