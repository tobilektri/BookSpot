import { shallowRef } from 'vue'
import { api } from './api.js'
import { getMeta, setMeta } from './db.js'

/** Cached shelf list (used by location pickers, also offline). */
export const shelves = shallowRef([])
let loaded = false

export async function loadShelves({ force = false } = {}) {
  if (loaded && !force) return shelves.value
  try {
    const data = await api('shelves.php')
    shelves.value = data.items
    loaded = true
    await setMeta('shelves', data.items)
  } catch (e) {
    const cached = await getMeta('shelves', [])
    if (!cached.length) throw e
    shelves.value = cached
  }
  return shelves.value
}

export function invalidateShelves() {
  loaded = false
}

export function compartmentName(shelf, col, row) {
  return shelf?.columns?.[col - 1]?.rows?.[row - 1] ?? null
}
