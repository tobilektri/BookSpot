import { reactive } from 'vue'

/**
 * "Actively offering" the PWA: install prompt and push notifications.
 * Platform detection, the captured beforeinstallprompt event and a snooze
 * schedule that asks again at growing intervals until the user accepts.
 */

function detectPlatform() {
  const ua = navigator.userAgent || ''
  const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const iosVersion = (() => {
    const m = ua.match(/OS (\d+)[_.](\d+)/)
    return m ? Number(m[1]) + Number(m[2]) / 100 : null
  })()
  const standalone = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
  return {
    ios,
    iosVersion,
    // In-app browsers (Instagram, Facebook, Google app …) cannot add to the home screen.
    iosInApp: ios && /FBAN|FBAV|Instagram|Line\/|GSA\/|Snapchat|TikTok/i.test(ua),
    iosSafari: ios && !/CriOS|FxiOS|EdgiOS|OPiOS|FBAN|FBAV|Instagram|GSA\//i.test(ua),
    android: /Android/i.test(ua),
    windows: /Windows/i.test(ua),
    macSafari: !ios && /Macintosh/.test(ua) && /Safari\//.test(ua) && !/Chrome|Chromium|Edg\//.test(ua),
    // Chrome/Edge/Brave/Opera on Windows, macOS, Linux, ChromeOS
    desktopChromium: !ios && !/Android/i.test(ua) && /Chrome\/|Chromium\/|Edg\//.test(ua),
    edge: /Edg\//.test(ua),
    firefox: /Firefox|FxiOS/i.test(ua),
    standalone,
  }
}

export const platform = detectPlatform()

export const install = reactive({
  promptEvent: null, // captured beforeinstallprompt (Chrome/Edge/Samsung on Android & Windows)
  installed: platform.standalone,
})

/** Must run as early as possible – the event fires once, shortly after page load. */
export function initInstallCapture() {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    install.promptEvent = event
  })
  window.addEventListener('appinstalled', () => {
    install.installed = true
    install.promptEvent = null
    resetNudge('install')
  })
  window.matchMedia?.('(display-mode: standalone)').addEventListener?.('change', (e) => {
    if (e.matches) install.installed = true
  })
}

/** Which kind of install help fits this device: 'prompt' | 'ios' | 'ios-inapp' | 'android-menu' | 'mac-safari' | 'desktop-chromium' | null */
export function installMethod() {
  if (install.installed) return null
  if (install.promptEvent) return 'prompt'
  if (platform.iosInApp) return 'ios-inapp'
  if (platform.ios) return 'ios'
  if (platform.android) return 'android-menu'
  if (platform.macSafari) return 'mac-safari'
  // Chrome/Edge without install event: already installed, or the site is not (yet)
  // installable. Show the menu path instead of claiming the browser cannot install.
  if (platform.desktopChromium) return 'desktop-chromium'
  return null // e.g. Firefox on desktop: no PWA installation
}

export async function promptInstall() {
  const event = install.promptEvent
  if (!event) return 'unavailable'
  event.prompt()
  const { outcome } = await event.userChoice
  install.promptEvent = null // the event can only be used once
  if (outcome === 'accepted') install.installed = true
  return outcome
}

// ---------------------------------------------------------------- snoozing

const SCHEDULES = {
  install: [3, 7, 14, 30], // days until the next reminder; the last value repeats
  push: [1, 3, 7, 14],
}
const DAY = 86_400_000

function readNudge(key) {
  try {
    return JSON.parse(localStorage.getItem(`bookspot.nudge.${key}`) || 'null') ?? { count: 0, next: 0 }
  } catch {
    return { count: 0, next: 0 }
  }
}

export function nudgeDue(key) {
  return Date.now() >= readNudge(key).next
}

/** "Later": ask again after the next interval of the schedule. */
export function snoozeNudge(key) {
  const state = readNudge(key)
  const schedule = SCHEDULES[key]
  const days = schedule[Math.min(state.count, schedule.length - 1)]
  try {
    localStorage.setItem(`bookspot.nudge.${key}`, JSON.stringify({ count: state.count + 1, next: Date.now() + days * DAY }))
  } catch {
    /* storage unavailable: the prompt simply shows again next time */
  }
  return days
}

export function resetNudge(key) {
  try {
    localStorage.removeItem(`bookspot.nudge.${key}`)
  } catch {
    /* ignore */
  }
}
