import { store } from './store.js'

export class ApiError extends Error {
  constructor(code, status = 0, details = {}) {
    super(code)
    this.code = code
    this.status = status
    this.details = details
  }

  get offline() {
    return this.code === 'offline'
  }

  /** Field validation codes: { title: 'required', … } */
  get fields() {
    return this.details?.errors ?? {}
  }
}

/**
 * Calls a PHP endpoint in ../api relative to the app (works in sub-directories).
 * PUT/PATCH/DELETE are tunnelled through POST because some shared hosters block them.
 */
export async function api(endpoint, { method = 'GET', query, body, form, signal } = {}) {
  const url = new URL(`api/${endpoint}`, document.baseURI)
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '' && value !== false) url.searchParams.set(key, value)
  }
  const headers = { Accept: 'application/json', 'X-Requested-With': 'BookSpot' }
  let realMethod = method
  if (!['GET', 'POST'].includes(method)) {
    headers['X-HTTP-Method-Override'] = method
    realMethod = 'POST'
  }
  let payload
  if (form) {
    payload = form
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  let response
  try {
    response = await fetch(url, { method: realMethod, headers, body: payload, credentials: 'same-origin', signal })
  } catch (e) {
    if (e.name === 'AbortError') throw e
    store.online = false
    throw new ApiError('offline', 0)
  }
  store.online = true

  let data = null
  try {
    data = await response.json()
  } catch {
    // Non-JSON response (e.g. PHP fatal error page or hoster maintenance page).
  }
  // Responses served by the service worker from cache while offline.
  if (response.headers.get('X-BookSpot-Offline') === '1') store.online = false

  if (!response.ok) {
    const code = data?.error ?? (response.status >= 500 ? 'server_error' : 'request_failed')
    if (response.status === 401 && code === 'unauthenticated') store.user = null
    throw new ApiError(code, response.status, data ?? {})
  }
  return data
}

export const get = (endpoint, query, opts) => api(endpoint, { ...opts, query })
export const post = (endpoint, body, query) => api(endpoint, { method: 'POST', body, query })
export const put = (endpoint, body, query) => api(endpoint, { method: 'PUT', body, query })
export const del = (endpoint, query) => api(endpoint, { method: 'DELETE', query })
