import { reactive } from 'vue'
import { api } from './api.js'
import { platform } from './engagement.js'

export function pushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/** Device-level push status, shared by the settings panel and the reminder prompt. */
export const pushState = reactive({
  loaded: false,
  supported: pushSupported(),
  serverAvailable: false,
  publicKey: null,
  permission: typeof Notification === 'undefined' ? 'default' : Notification.permission,
  subscribed: false,
})

/** On iPhone/iPad push only works inside the installed home screen app (iOS 16.4+). */
export const pushNeedsInstall = () => platform.ios && !platform.standalone

function urlBase64ToUint8Array(base64) {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}

export async function currentSubscription() {
  if (!pushSupported()) return null
  const reg = await navigator.serviceWorker.getRegistration()
  return (await reg?.pushManager.getSubscription()) ?? null
}

/**
 * Loads server + device status. An existing browser subscription is re-sent to
 * the server (idempotent), so a reset database or a new account on the same
 * device does not silently stop the reminders.
 */
export async function refreshPushState() {
  pushState.supported = pushSupported()
  if (typeof Notification !== 'undefined') pushState.permission = Notification.permission
  try {
    const info = await api('v1/push')
    pushState.serverAvailable = Boolean(info.available && info.public_key)
    pushState.publicKey = info.public_key
    const subscription = await currentSubscription()
    pushState.subscribed = Boolean(subscription) && pushState.permission === 'granted'
    if (pushState.subscribed) {
      await api('v1/push/subscriptions', { method: 'POST', body: subscription.toJSON() }).catch(() => {})
    }
  } catch {
    // offline: keep the last known state
  } finally {
    pushState.loaded = true
  }
  return pushState
}

/** Whether we should (still) offer push on this device. */
export function pushOfferable() {
  return pushState.loaded && pushState.supported && pushState.serverAvailable && !pushState.subscribed && !pushNeedsInstall()
}

export async function enablePush() {
  const permission = await Notification.requestPermission()
  pushState.permission = permission
  if (permission !== 'granted') throw Object.assign(new Error('denied'), { code: 'denied' })
  const reg = await navigator.serviceWorker.ready
  let subscription = await reg.pushManager.getSubscription()
  if (!subscription) {
    subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(pushState.publicKey) })
  }
  await api('v1/push/subscriptions', { method: 'POST', body: subscription.toJSON() })
  pushState.subscribed = true
  return subscription
}

export async function disablePush() {
  const subscription = await currentSubscription()
  if (subscription) {
    await api('v1/push/subscriptions', { method: 'DELETE', body: { endpoint: subscription.endpoint } }).catch(() => {})
    await subscription.unsubscribe()
  }
  pushState.subscribed = false
}
