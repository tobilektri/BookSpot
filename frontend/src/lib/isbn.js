/** Client-side ISBN helpers (mirror of backend/src/Isbn.php). */
export function cleanIsbn(raw) {
  let v = String(raw ?? '').toUpperCase().replace(/[^0-9X]/g, '')
  if (v.slice(0, -1).includes('X')) v = v.replace(/X/g, '')
  return v
}

export function isValid13(isbn) {
  if (!/^\d{13}$/.test(isbn)) return false
  const sum = [...isbn.slice(0, 12)].reduce((s, d, i) => s + Number(d) * (i % 2 ? 3 : 1), 0)
  return (10 - (sum % 10)) % 10 === Number(isbn[12])
}

export function isValid10(isbn) {
  if (!/^\d{9}[\dX]$/.test(isbn)) return false
  const sum = [...isbn].reduce((s, d, i) => s + (d === 'X' ? 10 : Number(d)) * (10 - i), 0)
  return sum % 11 === 0
}

export function to13(isbn10) {
  const base = '978' + isbn10.slice(0, 9)
  const sum = [...base].reduce((s, d, i) => s + Number(d) * (i % 2 ? 3 : 1), 0)
  return base + ((10 - (sum % 10)) % 10)
}

/** Returns a normalised ISBN-13 or null. Accepts EAN-13 starting with 978/979 and ISBN-10. */
export function normalizeIsbn(raw) {
  const v = cleanIsbn(raw)
  if (v.length === 13 && /^97[89]/.test(v)) return v
  if (v.length === 10 && isValid10(v)) return to13(v)
  return null
}
