import { api } from './api.js'
import { store } from './store.js'
import { setLocale } from './i18n.js'
import { db } from './db.js'
import { flushQueue } from './sync.js'

/** Loads auth status once at app start (works offline via the SW cache). */
export async function bootstrap() {
  try {
    const status = await api('auth.php', { query: { action: 'status' } })
    applyStatus(status)
  } catch (e) {
    // Offline and nothing cached: let the user in with the last known identity.
    const lastUser = JSON.parse(localStorage.getItem('bookspot.user') || 'null')
    store.user = lastUser
  } finally {
    store.ready = true
  }
  if (store.user) flushQueue()
}

export function applyStatus(status) {
  store.user = status.user
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
  await api('auth.php', { method: 'POST', query: { action: 'logout' } }).catch(() => {})
  rememberUser(null)
  // Personal data must not stay on a shared device.
  try {
    await db.books.clear()
    await db.queue.clear()
    await db.meta.clear()
  } catch {
    /* ignore */
  }
  navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_API_CACHE' })
  if ('caches' in window) await caches.delete('bookspot-api').catch(() => {})
}
