# Offline use & installing the app

[← README](../README.md)

BookSpot is a Progressive Web App: install it like a native app and keep using it in the cellar
without signal.

## Installing

| Platform | How |
|---|---|
| Android (Chrome, Edge, Samsung Internet) | the app offers *Install* on its own, or browser menu → *Install app* |
| Windows, macOS, Linux (Chrome, Edge) | install icon in the address bar or the app's own offer |
| iPhone / iPad (Safari) | *Share* → *Add to Home Screen* – the app shows an illustrated guide (also for in-app browsers that cannot install) |
| macOS Safari 17+ | *File* → *Add to Dock* |

If you tap *Later*, the offer comes back after 3, 7, 14 and then every 30 days until the app is
installed; it never shows inside the installed app. *Settings → Install app* is always there.

## What works offline

| Works offline | Needs a connection |
|---|---|
| The whole app shell (precached by the service worker) | Looking up book data for new ISBNs |
| Book list, search, filters, book pages, shelves (last synchronised state) | Creating books with the full form, editing catalogue data |
| Changing your reading status, progress, rating, notes | Import, user management |
| Moving books between compartments | |
| **Batch scanning** – scans are stored on the device | |
| Cover images you have seen before | |

An amber bar shows when you are offline and how many changes are waiting.

## How synchronisation works

- **Reads**: API requests go *network first*; when the network is gone the service worker answers
  from its cache (marked as offline copy). The full catalogue is also kept in IndexedDB (Dexie).
- **Writes made offline** (reading data, moves) go into a queue in IndexedDB and are replayed in order
  via `POST /api/v1/sync` as soon as the connection is back – also triggered by Background Sync,
  when the app comes to the foreground and once a minute.
- **Conflicts**: every change carries the time it was made. If the same data was changed on the
  server later (e.g. on another device), the newer version wins and the app tells you.
- **Batch scans** have their own queue and are uploaded the same way ([scanning](scanning.md#what-happens-behind-the-scenes)).

## Updates

When a new version is deployed, the service worker downloads it in the background and the app shows
**“New version available – Reload”**. Until then the old version keeps running, including its
cached files.

## Data on the device

Signing out (or a session that ended on the server) deletes the cached catalogue, the offline queue,
unsent scans and the API cache from the device – the next person on a shared tablet starts clean.
