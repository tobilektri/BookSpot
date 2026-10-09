import { ref, shallowRef } from 'vue'
import { api } from './api.js'
import { cacheBooks, cachedBooks, getMeta, setMeta } from './db.js'
import { store } from './store.js'

/**
 * Whole catalogue (compact, incl. own reading data) kept in memory and in
 * IndexedDB. Lists, filters and the scanner work on this copy, so they keep
 * working offline. Collections of a few thousand books are no problem.
 */
export const books = shallowRef([])
export const loadedAt = ref(null)
export const fromCache = ref(false)
export const loading = ref(false)

let pending = null
let stale = true

export function invalidateCatalog() {
  stale = true
}

export function loadCatalog({ force = false } = {}) {
  if (!force && !stale && books.value.length) return Promise.resolve(books.value)
  if (pending) return pending
  loading.value = true
  pending = (async () => {
    // Show the cached copy immediately, then refresh from the server.
    if (!books.value.length) {
      const cached = await cachedBooks()
      if (cached.length) {
        books.value = cached
        fromCache.value = true
        loadedAt.value = await getMeta('catalogLoadedAt')
      }
    }
    try {
      const data = await api('books.php', { query: { limit: 10000, compact: 1, sort: 'title' } })
      books.value = data.items
      fromCache.value = !store.online // served by the service worker cache
      if (store.online) {
        loadedAt.value = new Date().toISOString()
        stale = false
        await cacheBooks(data.items, { replace: true })
        await setMeta('catalogLoadedAt', loadedAt.value)
      }
    } catch (e) {
      if (!books.value.length) throw e
      fromCache.value = true
    } finally {
      loading.value = false
      pending = null
    }
    return books.value
  })()
  return pending
}

/** Update a single book in the in-memory list after an edit. */
export function patchBook(book) {
  const index = books.value.findIndex((b) => b.id === book.id)
  const next = [...books.value]
  if (index === -1) next.push(book)
  else next[index] = { ...next[index], ...book }
  books.value = next
  cacheBooks([next[index === -1 ? next.length - 1 : index]])
}

export function removeBook(id) {
  books.value = books.value.filter((b) => b.id !== id)
}

/** Simple accent/case-insensitive matcher. */
export function normalizeText(value) {
  return String(value ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
}
