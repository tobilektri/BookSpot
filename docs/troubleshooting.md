# Troubleshooting

[← README](../README.md)

## Installation

**The site shows “404 Not Found” when the domain points to `public/`**
Fixed in 1.1.1. Older versions inherited the rewrite rules of the project folder's `.htaccess` inside
`public/` (Apache reads the `.htaccess` of every parent folder) and pointed to `public/public/…`.
Update – `public/.htaccess` now has its own rewrite context.

**`/api/v1/…` returns 404, the old `api/books.php` works**
URL rewriting is not active: Apache needs `mod_rewrite` and `AllowOverride All` for the folder (on
shared hosting usually the case). With nginx use the `location ^~ /api/v1` block from
`docker/nginx.conf`. As a workaround `api/index.php/v1/…` works without rewriting.

**“server_error” right after the upload**
Check `var/error.log` (or the PHP error log of your host). Common causes: wrong database credentials
in `.env`, PHP older than 8.2, `var/` not writable. Temporarily setting `APP_ENV=development` adds the
error message to API responses.

**After emptying the database the login page appears instead of the setup**
In current versions the tables are recreated automatically on the next request and the first-run
setup appears again. Reload the page (and update if you run a very old version).

## Installing the app

**“Install app” is not offered / icons return 404**
Up to 1.0 the icons lived under `/icons/`. Many Apache servers map `/icons/` globally to their own
symbol folder (`Alias /icons/`, applied before any `.htaccess`), so the app icons were missing, Chrome
refused to install and the service worker did not start. Since 1.1 they are under `/app-icons/` –
after updating, delete the old `public/icons/` folder.

**iPhone: no install button**
iOS has no install prompt – use *Share → Add to Home Screen* in Safari. In in-app browsers (Instagram,
WhatsApp …) open the page in Safari first. The app shows a guide.

## Scanner

**“No camera available”** – the site must be served via HTTPS (or `localhost`), the camera permission
must be granted, and no other app may be using the camera.

**Nothing is recognised** – more light (torch button), 15–25 cm distance, barcode horizontal inside
the frame. Browsers without a native EAN-13 detector use ZXing, which does not detect barcodes turned
by 90°.

**Console full of `MultiFormatReader … Could not create a Canvas element`** – fixed in 1.6.2 (bugs in
`@zxing/browser`/`@zxing/library`, worked around in `frontend/src/lib/scanner.js`).

## Book data

**Books stay at “Loading book data …”**
The queue is worked off while the scanner page is open and by the cron job. Check that the
[cron URL](installation.md#7-cron-job) is called. If a source answered 429 it is paused for a while –
the other sources are used meanwhile, the rest follows later ([rate limits](metadata.md#rate-limits)).

**Google Books never delivers anything** – without your own key Google answers 429.
[Create a free key](google-books-api-key.md).

**No covers** – covers are downloaded by the server into `var/covers/` (must be writable). Open
Library limits cover requests by ISBN to 100 per 5 minutes; the cron job catches up over time.

## Push notifications

**The test notification does not arrive** – check the notification permission of the browser/OS for
the site; on iOS the app must be installed to the home screen first. While signed in *as another
user*, push cannot be enabled.

**The test arrives, reminders don't** – reminders are sent by the cron job. Call the cron URL in the
browser: the JSON answer shows `reminders.checked` and `reminders.sent`.

## Invitations

**Invitation e-mails don't arrive** – set `MAIL_FROM` to an address of your own domain; some hosts
only deliver mail from their own domains. If sending fails, the app shows the invitation link to pass
on yourself.

## Still stuck?

Open an [issue](https://github.com/tobilektri/BookSpot/issues) with your BookSpot version
(*Settings → About*), PHP version, hoster/Docker and the relevant lines of `var/error.log`.
