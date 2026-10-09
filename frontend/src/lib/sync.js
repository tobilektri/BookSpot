import { api, ApiError } from './api.js'
import { db } from './db.js'
import { store, toast } from './store.js'
import { t } from './i18n.js'

/**
 * Offline-first writes for personal reading data and book moves.
 * Online: sent directly. Offline (or network error): stored in the Dexie
 * queue and replayed in order via api/sync.php when the connection returns.
 */

async function patchCachedBook(bookId, personalPatch, bookPatch = {}) {
  try {
    const cached = await db.books.get(bookId)
    if (cached) {
      await db.books.put({ ...cached, ...bookPatch, personal: { ...(cached.personal ?? {}), ...personalPatch } })
    }
  } catch {
    /* ignore */
  }
}

export async function refreshPendingCount() {
  try {
    store.pendingOps = await db.queue.count()
  } catch {
    store.pendingOps = 0
  }
}

async function enqueue(op) {
  await db.queue.add({ ...op, id: crypto.randomUUID?.() ?? String(Date.now() + Math.random()), client_updated_at: new Date().toISOString() })
  await refreshPendingCount()
  registerBackgroundSync()
}

/** Saves personal data (status, rating, progress, …). Returns the new personal object. */
export async function savePersonal(bookId, data) {
  if (store.online) {
    try {
      const result = await api('user_books.php', { method: 'PUT', query: { book_id: bookId }, body: data })
      await patchCachedBook(bookId, result.personal)
      return { personal: result.personal, queued: false }
    } catch (e) {
      if (!(e instanceof ApiError) || !e.offline) throw e
    }
  }
  await enqueue({ type: 'personal', book_id: bookId, data })
  await patchCachedBook(bookId, data)
  return { personal: data, queued: true }
}

/** Moves books to a compartment; queued when offline. */
export async function moveBooks(bookIds, shelfId, col, row) {
  const body = { ids: bookIds, shelf_id: shelfId, shelf_col: col, shelf_row: row }
  if (store.online) {
    try {
      return { ...(await api('books.php', { method: 'POST', query: { action: 'move' }, body })), queued: false }
    } catch (e) {
      if (!(e instanceof ApiError) || !e.offline) throw e
    }
  }
  await enqueue({ type: 'move', book_ids: bookIds, shelf_id: shelfId, shelf_col: col, shelf_row: row })
  for (const id of bookIds) await patchCachedBook(id, {}, { shelf_id: shelfId, shelf_col: col, shelf_row: row })
  return { moved: bookIds.length, queued: true }
}

let flushing = null

/** Replays the offline queue. Safe to call often. */
export function flushQueue() {
  if (flushing) return flushing
  flushing = (async () => {
    try {
      const ops = await db.queue.orderBy('seq').toArray()
      if (!ops.length || !store.user) return
      store.syncing = true
      const payload = ops.map(({ seq, ...op }) => op)
      const result = await api('sync.php', { method: 'POST', body: { ops: payload } })
      const conflicts = result.results.filter((r) => r.status === 'conflict').length
      const errors = result.results.filter((r) => r.status === 'error').length
      await db.queue.bulkDelete(ops.map((o) => o.seq))
      for (const r of result.results) {
        const op = ops.find((o) => o.id === r.id)
        if (op?.type === 'personal' && r.personal) await patchCachedBook(op.book_id, r.personal)
      }
      toast(t('sync.done', { n: ops.length }), 'success')
      if (conflicts) toast(t('sync.conflicts', { n: conflicts }), 'info', 6000)
      if (errors) toast(t('sync.errors', { n: errors }), 'error', 6000)
    } catch (e) {
      if (!(e instanceof ApiError) || !e.offline) console.warn('Sync failed', e)
    } finally {
      store.syncing = false
      flushing = null
      await refreshPendingCount()
    }
  })()
  return flushing
}

function registerBackgroundSync() {
  navigator.serviceWorker?.ready
    .then((reg) => reg.sync?.register('bookspot-sync'))
    .catch(() => {})
}

export function initSync() {
  refreshPendingCount()
  window.addEventListener('online', () => {
    store.online = true
    flushQueue()
  })
  window.addEventListener('offline', () => {
    store.online = false
  })
  // The service worker asks open pages to flush when Background Sync fires.
  navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.type === 'bookspot-sync') flushQueue()
  })
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && store.online) flushQueue()
  })
  setInterval(() => store.online && store.pendingOps && flushQueue(), 60_000)
}
