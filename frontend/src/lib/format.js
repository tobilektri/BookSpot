import { locale } from './i18n.js'

export function formatDate(value, opts = { day: '2-digit', month: '2-digit', year: 'numeric' }) {
  if (!value) return ''
  const d = new Date(`${String(value).slice(0, 10)}T12:00:00`)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString(locale.value === 'en' ? 'en-GB' : 'de-DE', opts)
}

export function formatNumber(value, digits = 0) {
  if (value === null || value === undefined) return '–'
  return Number(value).toLocaleString(locale.value === 'en' ? 'en-GB' : 'de-DE', { maximumFractionDigits: digits, minimumFractionDigits: 0 })
}

export function formatPrice(value) {
  if (value === null || value === undefined) return ''
  return Number(value).toLocaleString(locale.value === 'en' ? 'en-GB' : 'de-DE', { style: 'currency', currency: 'EUR' })
}

export function todayIso(offsetDays = 0) {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * Cover URL – always on our own server, never an external host (the CSP only
 * allows img-src 'self'). Saved books use the locally stored copy; the form
 * preview of an unsaved/changed cover is fetched through the server.
 */
export function coverUrl(book) {
  if (!book) return null
  if (book.id) {
    if (book.has_cover === false) return null // known: no cover anywhere -> placeholder without request
    return new URL(`api/cover.php?id=${book.id}&v=${book.cover_version ?? ''}`, document.baseURI).href
  }
  const params = new URLSearchParams({ preview: '1' })
  if (book.cover_url) params.set('url', book.cover_url)
  else if (book.isbn13) params.set('isbn', book.isbn13)
  else return null
  return new URL(`api/cover.php?${params}`, document.baseURI).href
}

/** Stable pastel-ish colour for book spines / placeholders. */
export function bookColor(seed) {
  let hash = 0
  for (const ch of String(seed ?? '')) hash = (hash * 31 + ch.charCodeAt(0)) | 0
  const hue = Math.abs(hash) % 360
  return `hsl(${hue} 45% 45%)`
}

export function locationText(location) {
  if (!location) return ''
  return [location.shelf, location.compartment].filter(Boolean).join(' › ')
}
