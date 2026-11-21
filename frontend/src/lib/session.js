import { api } from './api.js'
import { store, toast } from './store.js'
import { setLocale, t } from './i18n.js'
import { db } from './db.js'
import { flushQueue, refreshPendingCount } from './sync.js'
import { loadScans, resetScans } from './batch.js'
import { resetCatalog } from './catalog.js'
import { disablePush, currentSubscription } from './push.js'

// Offline changes kept when the server ended the session (expired, logged out elsewhere):
// they belong to this user id and are sent after the same person signs in again.
const QUEUE_OWNER = 'bookspot.queueOwner'

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
  if (store.user) {
    flushQueue()
    loadScans() // sends bulk scans recorded offline
  }
}

/** Fetches the auth status (also used by the login page to detect a fresh/emptied database). */
export async function refreshStatus() {
  const status = await api('v1/auth/status')
  // The server no longer knows this session (expired, logged out elsewhere, account deleted,
  // database emptied): drop locally cached data of the previous user – but keep changes
  // not sent yet, they go out when the same person signs in again.
  const previous = lastKnownUser()
  if (!status.user && previous) await clearLocalData({ keepQueueFor: previous.id })
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
  if (user) settleKeptQueue(user)
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

/** Offline changes kept from an ended session: send them for the same user, drop them for another one. */
async function settleKeptQueue(user) {
  let owner = null
  try {
    owner = localStorage.getItem(QUEUE_OWNER)
    localStorage.removeItem(QUEUE_OWNER)
  } catch {
    return
  }
  if (owner === null) return
  if (Number(owner) === user.id) {
    flushQueue()
    return
  }
  const dropped = await db.queue.count().catch(() => 0)
  await db.queue.clear().catch(() => {})
  await refreshPendingCount()
  if (dropped) toast(t('sync.droppedForeign', { n: dropped }), 'info', 8000)
}

/**
 * Signs out. Only online: without the request the server session stays valid and the
 * next person using this device would be signed in again. Push reminders of this
 * account are switched off on this device first.
 */
export async function logout() {
  if (await currentSubscription().catch(() => null)) await disablePush().catch(() => {})
  await api('v1/auth/logout', { method: 'POST' }) // throws offline – the caller shows it
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

/**
 * Personal data and pending offline changes must not survive a logout / ended session.
 * keepQueueFor: keep the unsent changes of this user id (session ended by the server).
 */
export async function clearLocalData({ keepQueueFor = null } = {}) {
  resetCatalog()
  try {
    localStorage.removeItem('bookspot.user')
    if (keepQueueFor !== null && (await db.queue.count()) > 0) localStorage.setItem(QUEUE_OWNER, String(keepQueueFor))
    else {
      localStorage.removeItem(QUEUE_OWNER)
      await db.queue.clear()
    }
    await db.books.clear()
    await db.meta.clear()
    await resetScans()
  } catch {
    /* ignore */
  }
  await refreshPendingCount()
  navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_API_CACHE' })
  if ('caches' in window) await caches.delete('bookspot-api').catch(() => {})
}
