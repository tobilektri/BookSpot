import { api } from './api.js'
import { store } from './store.js'
import { setLocale } from './i18n.js'
import { db } from './db.js'
import { flushQueue } from './sync.js'

/** Loads auth status once at app start (works offline via the SW cache). */
export async function bootstrap() {
  try {
    await refreshStatus()
  } catch (e) {
    // Only when the server is unreachable we keep the last known identity (offline use).
    // A server error must not pretend a valid session.
    store.user = e?.code === 'offline' ? lastKnownUser() : null
  } finally {
    store.ready = true
  }
  if (store.user) flushQueue()
}

/** Fetches the auth status (also used by the login page to detect a fresh/emptied database). */
export async function refreshStatus() {
  const status = await api('v1/auth/status')
  // The server no longer knows this session (logged out elsewhere, account deleted,
  // database emptied): drop locally cached data of the previous user.
  if (!status.user && lastKnownUser()) await clearLocalData()
  applyStatus(status)
  return status
}

function lastKnownUser() {
  try {
    return JSON.parse(localStorage.getItem('bookspot.user') || 'null')
  } catch {
    return null
  }
}

export function applyStatus(status) {
  store.user = status.user
  store.impersonator = status.impersonator ?? null
  store.registrationOpen = status.registration_open
  store.needsSetup = status.needs_setup
  store.pushAvailable = status.push_available
  store.appName = status.app_name
  store.version = status.version
  rememberUser(status.user)
}

export function rememberUser(user) {
  store.user = user
  try {
    if (user) {
      localStorage.setItem('bookspot.user', JSON.stringify(user))
      if (user.locale) setLocale(user.locale)
    } else {
      localStorage.removeItem('bookspot.user')
    }
  } catch {
    /* ignore */
  }
}

export async function logout() {
  await api('v1/auth/logout', { method: 'POST' }).catch(() => {})
  rememberUser(null)
  await clearLocalData()
}

/**
 * Switches the logged-in identity (impersonation start/stop, invitation link):
 * runs the request, drops the previous user's cached data and reloads the app
 * so that no state of the other account survives.
 */
export async function switchIdentity(request, targetHash = '#/') {
  await request()
  await clearLocalData()
  window.location.hash = targetHash
  window.location.reload()
}

/** Personal data and pending offline changes must not survive a logout / ended session. */
export async function clearLocalData() {
  try {
    localStorage.removeItem('bookspot.user')
    await db.books.clear()
    await db.queue.clear()
    await db.meta.clear()
  } catch {
    /* ignore */
  }
  navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_API_CACHE' })
  if ('caches' in window) await caches.delete('bookspot-api').catch(() => {})
}
