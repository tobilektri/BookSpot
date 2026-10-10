# Creating a Google Books API key (free)

**English** · [Deutsch](de/google-books-api-key.md) · [← README](../README.md)

BookSpot asks these sources in order; later sources only fill fields that are still empty:

1. **Open Library** – covers, often only title and publisher.
2. **German National Library (DNB)** – free, no key, very complete for German-language editions
   (author, pages, year, publisher, subjects, blurb). Can be switched off with `METADATA_DNB=false`.
3. **Google Books** – for everything still missing (especially English titles and descriptions).

Google Books is therefore optional, but helpful for books in other languages. More about the sources:
[metadata.md](metadata.md).

## Why you need your own key

Requests **without** an API key use a quota shared by everybody, which is now set to `0`. The answer
is always:

```json
{ "error": { "code": 429, "status": "RESOURCE_EXHAUSTED",
  "message": "Quota exceeded for quota metric 'Queries' and limit 'Queries per day' …",
  "details": [{ "metadata": { "quota_limit": "defaultPerDayPerProject", "quota_limit_value": "0" } }] } }
```

With your own key your project gets its own **free daily quota** (1,000 requests per day by default –
the current value is shown in the Cloud Console under *Quotas*). The Books API is free; no billing
account (credit card) is needed.

## Step by step

1. **Open the Google Cloud Console**: <https://console.cloud.google.com/> – sign in with any Google
   account and accept the terms if asked.
2. **Create a project**: project picker at the top left → *New project* → name e.g. `BookSpot` →
   *Create*. Make sure the new project is selected afterwards.
3. **Enable the Books API**: menu ☰ → *APIs & Services* → *Library* → search **“Books API”** → *Enable*.
   (Direct link: <https://console.cloud.google.com/apis/library/books.googleapis.com>)
4. **Create an API key**: *APIs & Services* → *Credentials* → *Create credentials* → **API key**.
   Copy the key (it starts with `AIza…`).
5. **Restrict the key** (strongly recommended) – click the new key in the list:
   - **API restrictions** → *Restrict key* → select only **Books API**.
   - **Application restrictions**: the requests come from your server (PHP), not from the browser,
     so “HTTP referrers (websites)” does **not** work.
     - Server with a fixed outgoing IP (VPS, Docker host): choose **IP addresses** and enter it.
     - On shared hosting (e.g. [Lima-City](https://www.lima-city.de/?cref=253166) *(referral link¹)*)
       the outgoing IP can change – leave *None* and rely on the API restriction.
   - *Save*. Changes can take a few minutes.
6. **Add it to BookSpot** – in `.env` in the project folder:

   ```dotenv
   GOOGLE_BOOKS_API_KEY=AIza...your-key
   ```

   With Docker set the variable in the `.env` next to `docker-compose.yml` and restart:
   `docker compose up -d`.

## Testing

In the browser or terminal (insert your key):

```
https://www.googleapis.com/books/v1/volumes?q=isbn:9783548283951&key=AIza...
```

JSON with `"items": [...]` means the key works. In BookSpot: *Add book* → enter an ISBN → *Fetch data*,
or the *Fill in now* button that appears when books are missing covers or descriptions.

| Answer | Meaning |
|---|---|
| `429` with `quota_limit_value: "0"` | key missing or not sent (check `.env`) |
| `429` with a value > 0 | daily quota used up – continues tomorrow |
| `403` `API_KEY_SERVICE_BLOCKED` / `accessNotConfigured` | Books API not enabled in the project (step 3) |
| `403` `API_KEY_IP_ADDRESS_BLOCKED` | IP restriction does not match the server IP (step 5) |
| `400` `API key not valid` | key copied incorrectly |
| `200` with `"totalItems": 0` | key works, Google just doesn't know this edition (common for German paperbacks – Open Library and DNB help there) |

## How many requests does BookSpot need?

- **Scanning / adding**: at most 1 request per book – only when Open Library and DNB don't provide every field.
- **Filling in book data** after a CSV import: at most 1 request per book, so a collection of 500 books fits into the daily quota.
- **Cron**: fills in at most `CRON_ENRICH_BATCH` (default 20) books per run.
- **Batch scanning**: every new ISBN is looked up once via the queue.
- All sources are throttled (Google Books 5 requests per second by default, `RATE_LIMIT_GOOGLE_BOOKS`).
  If Google answers 429, BookSpot pauses the source (Retry-After, otherwise 1, 2, 4 … 60 minutes) and
  uses the others in the meantime.

If a source is unreachable or the quota is exhausted, BookSpot keeps the data of the other sources
and tries the missing fields again later. Existing data is never overwritten.

## Security

- The key only lives in `.env` on the server and is never sent to the browser.
- Don't commit `.env` to Git (it is listed in `.gitignore`).
- If you suspect misuse: regenerate the key in the console under *Credentials*.

---

¹ *Referral link: if you sign up with Lima-City through this link, the maintainer may receive a commission.*
