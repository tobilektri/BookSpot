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

/** Cover URL with Open Library fallback by ISBN (no API call needed). */
export function coverUrl(book, size = 'M') {
  if (book?.cover_url) return book.cover_url
  if (book?.isbn13) return `https://covers.openlibrary.org/b/isbn/${book.isbn13}-${size}.jpg?default=false`
  return null
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
