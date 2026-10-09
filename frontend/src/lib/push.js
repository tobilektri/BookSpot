import { api } from './api.js'

export function pushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

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

export async function enablePush(publicKey) {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw Object.assign(new Error('denied'), { code: 'denied' })
  const reg = await navigator.serviceWorker.ready
  let subscription = await reg.pushManager.getSubscription()
  if (!subscription) {
    subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) })
  }
  await api('push.php', { method: 'POST', query: { action: 'subscribe' }, body: subscription.toJSON() })
  return subscription
}

export async function disablePush() {
  const subscription = await currentSubscription()
  if (!subscription) return
  await api('push.php', { method: 'POST', query: { action: 'unsubscribe' }, body: { endpoint: subscription.endpoint } }).catch(() => {})
  await subscription.unsubscribe()
}
