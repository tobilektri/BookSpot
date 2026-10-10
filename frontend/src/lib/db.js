import Dexie from 'dexie'

/**
 * IndexedDB (Dexie) storage for offline use:
 *  - books: last known copy of the catalogue incl. the user's personal data
 *  - queue: changes made offline, replayed against api/v1/sync on reconnect
 *  - meta:  misc. key/value pairs (last sync time, cached shelves, …)
 */
export const db = new Dexie('bookspot')

db.version(1).stores({
  books: 'id, isbn13, isbn10, title, shelf_id',
  queue: '++seq, book_id, type',
  meta: 'key',
})

export async function getMeta(key, fallback = null) {
  try {
    return (await db.meta.get(key))?.value ?? fallback
  } catch {
    return fallback
  }
}

export async function setMeta(key, value) {
  try {
    await db.meta.put({ key, value: JSON.parse(JSON.stringify(value)) })
  } catch {
    // Private mode / storage disabled: offline features degrade gracefully.
  }
}

/** Replace the cached catalogue (called after a full list fetch). */
export async function cacheBooks(books, { replace = false } = {}) {
  try {
    const plain = JSON.parse(JSON.stringify(books))
    await db.transaction('rw', db.books, async () => {
      if (replace) await db.books.clear()
      await db.books.bulkPut(plain)
    })
  } catch (e) {
    console.warn('Could not cache books', e)
  }
}

export async function cachedBooks() {
  try {
    return await db.books.toArray()
  } catch {
    return []
  }
}
