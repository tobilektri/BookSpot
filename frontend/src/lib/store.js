import { reactive } from 'vue'

/** Global reactive app state (tiny alternative to Pinia). */
export const store = reactive({
  ready: false,
  user: null,
  registrationOpen: false,
  needsSetup: false,
  pushAvailable: false,
  appName: null,
  version: '',
  online: typeof navigator === 'undefined' ? true : navigator.onLine,
  pendingOps: 0,
  syncing: false,
  toasts: [],
  updateAvailable: false,
  pushOffer: null, // set to a reason (e.g. 'loan') to offer push notifications right away
})

let toastId = 0

/** type: 'info' | 'success' | 'error' */
export function toast(message, type = 'info', timeout = 3500) {
  const id = ++toastId
  store.toasts.push({ id, message, type })
  if (timeout) setTimeout(() => dismissToast(id), timeout)
  return id
}

export function dismissToast(id) {
  const index = store.toasts.findIndex((t) => t.id === id)
  if (index !== -1) store.toasts.splice(index, 1)
}
