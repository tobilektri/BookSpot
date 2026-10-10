import { reactive, computed } from 'vue'
import { api, ApiError } from './api.js'
import { db } from './db.js'
import { store } from './store.js'
import { patchBook } from './catalog.js'

/**
 * Bulk scan ("beep & continue").
 *
 * Every scan is stored in IndexedDB at once (state 'local') and shows up in the
 * list immediately. When online, local scans are sent in batches to
 * api/v1/books/batch – new ISBNs become placeholder books in the chosen
 * compartment. While placeholders wait for their metadata, the app asks
 * api/v1/books/batch/process every few seconds; that call also works off the
 * server's rate-limited metadata queue (cron does the rest when the app is closed).
 *
 * scan.state: 'local' (not sent yet) | 'sent' (accepted) | 'error' (rejected, e.g. invalid ISBN)
 * scan.result: created | existing | existing_here | moved | duplicate | invalid | invalid_location
 * scan.book.metadata_status: 'pending' | 'failed' | null (done)
 */
export const scans = reactive({ items: [], loaded: false })

export const scanCounts = computed(() => {
  const total = scans.items.length
  const waiting = scans.items.filter((s) => s.state === 'local' || s.book?.metadata_status === 'pending').length
  return { total, waiting, done: total - waiting }
})

const BOOK_FIELDS = ['id', 'isbn13', 'title', 'authors', 'metadata_status', 'location', 'cover_version', 'has_cover', 'cover_url']

function pick(book) {
  return Object.fromEntries(BOOK_FIELDS.map((key) => [key, book[key] ?? null]))
}

async function save(scan) {
  try {
    await db.scans.put(JSON.parse(JSON.stringify(scan)))
  } catch {
    /* storage unavailable: the scan still works while the page is open */
  }
}

export async function loadScans() {
  if (scans.loaded) return
  try {
    scans.items = await db.scans.orderBy('scanned_at').reverse().toArray()
  } catch {
    scans.items = []
  }
  scans.loaded = true
  flushScans()
  startProcessing()
}

/**
 * Records a scan. Returns { duplicate: true, scan } when this ISBN is already in the list.
 *
 * @param {string} isbn normalised ISBN-13
 * @param {{shelf_id: number, shelf_col: number, shelf_row: number}} location
 * @param {{ label?: string, moveExisting?: boolean }} options
 */
export async function addScan(isbn, location, { label = '', moveExisting = true } = {}) {
  await loadScans()
  const existing = scans.items.find((s) => s.isbn === isbn)
  if (existing) return { duplicate: true, scan: existing }
  scans.items.unshift({
    id: crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`,
    isbn,
    shelf_id: location.shelf_id,
    shelf_col: location.shelf_col,
    shelf_row: location.shelf_row,
    location_label: label,
    move_existing: moveExisting,
    state: 'local',
    result: null,
    previous_location: null,
    book: null,
    scanned_at: new Date().toISOString(),
  })
  const scan = scans.items[0] // reactive proxy
  await save(scan)
  flushScans()
  return { duplicate: false, scan }
}

let flushing = null

/** Sends local scans (in batches). Safe to call often; does nothing offline. */
export function flushScans() {
  if (flushing || !store.online || !store.user) return flushing
  // Reset via .finally(): with nothing to send the async body finishes synchronously, and a
  // reset inside it would run before the assignment – blocking every later flush.
  flushing = sendLocalScans().finally(() => {
    flushing = null
  })
  return flushing
}

async function sendLocalScans() {
  try {
    for (;;) {
      const local = scans.items.filter((s) => s.state === 'local').reverse() // oldest first
      if (!local.length) break
      const moveExisting = local[0].move_existing
      const chunk = local.filter((s) => s.move_existing === moveExisting).slice(0, 50)
      const result = await api('v1/books/batch', {
        method: 'POST',
        body: {
          move_existing: moveExisting,
          items: chunk.map((s) => ({ client_id: s.id, isbn: s.isbn, shelf_id: s.shelf_id, shelf_col: s.shelf_col, shelf_row: s.shelf_row })),
        },
      })
      for (const item of result.items) {
        const scan = scans.items.find((s) => s.id === item.client_id)
        if (!scan) continue
        scan.state = ['invalid', 'invalid_location'].includes(item.status) ? 'error' : 'sent'
        scan.result = item.status
        scan.previous_location = item.previous_location ?? null
        if (item.book) {
          scan.book = pick(item.book)
          patchBook(item.book)
        }
        await save(scan)
      }
    }
    startProcessing()
  } catch (e) {
    // Offline or rate limited: the scans stay local and are sent with the next flush.
    if (!(e instanceof ApiError) || (!e.offline && e.status !== 429)) console.warn('Bulk scan upload failed', e)
  }
}

let processTimer = null
let processing = false

/** Polls the metadata queue while placeholders of this device are still pending. */
export function startProcessing() {
  if (processing) return
  processing = true
  clearTimeout(processTimer)
  processTimer = setTimeout(processTick, 300)
}

async function processTick() {
  const pending = scans.items.filter((s) => s.book?.metadata_status === 'pending')
  if (!pending.length || !store.user) {
    processing = false
    return
  }
  let delay = 2500
  if (store.online) {
    try {
      const result = await api('v1/books/batch/process', { method: 'POST', body: { book_ids: [...new Set(pending.map((s) => s.book.id))] } })
      for (const book of result.books ?? []) {
        for (const scan of scans.items.filter((s) => s.book?.id === book.id)) {
          scan.book = pick(book)
          await save(scan)
        }
        patchBook(book)
      }
      // Nothing processed although entries are waiting: the sources are busy – ask less often.
      if (result.processed === 0 && result.remaining > 0) delay = 10_000
    } catch (e) {
      delay = e instanceof ApiError && e.status === 429 ? (e.details?.retry_after ?? 20) * 1000 : 10_000
    }
  } else {
    delay = 10_000
  }
  processTimer = setTimeout(processTick, delay)
}

/** Removes finished scans from the list (the books stay in the catalogue). */
export async function clearFinishedScans() {
  const finished = scans.items.filter((s) => s.state !== 'local' && s.book?.metadata_status !== 'pending')
  scans.items = scans.items.filter((s) => !finished.includes(s))
  try {
    await db.scans.bulkDelete(finished.map((s) => s.id))
  } catch {
    /* ignore */
  }
}

/** Logout / user switch. */
export async function resetScans() {
  clearTimeout(processTimer)
  processing = false
  scans.items = []
  scans.loaded = false
  try {
    await db.scans.clear()
  } catch {
    /* ignore */
  }
}
