import { normalizeIsbn } from './isbn.js'

const CAMERA_KEY = 'bookspot.camera'

/** All cameras of the device. Labels are only available after camera permission was granted. */
export async function listCameras() {
  try {
    const devices = await navigator.mediaDevices.enumerateDevices()
    return devices.filter((d) => d.kind === 'videoinput' && d.deviceId)
  } catch {
    return []
  }
}

export function rememberedCamera() {
  try {
    return localStorage.getItem(CAMERA_KEY)
  } catch {
    return null
  }
}

function rememberCamera(deviceId) {
  try {
    localStorage.setItem(CAMERA_KEY, deviceId)
  } catch {
    /* ignore */
  }
}

async function openStream(deviceId) {
  const size = { width: { ideal: 1280 }, height: { ideal: 720 } }
  if (deviceId) {
    try {
      return await navigator.mediaDevices.getUserMedia({ audio: false, video: { deviceId: { exact: deviceId }, ...size } })
    } catch (e) {
      // Remembered camera no longer exists (other device, unplugged): fall back to the rear camera.
      if (!['OverconstrainedError', 'NotFoundError'].includes(e?.name)) throw e
    }
  }
  return navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, ...size } })
}

/**
 * Camera barcode scanner. Uses the native BarcodeDetector API where available
 * (Chrome/Android, Safari 17+) and falls back to ZXing (lazy-loaded) elsewhere.
 * Only valid ISBN-13/EAN codes starting with 978/979 are reported.
 *
 * options.deviceId selects a camera; without it the last used camera (or the
 * rear camera) is opened. The chosen camera is remembered on this device.
 */
export async function startScanner(video, onIsbn, { deviceId = rememberedCamera() } = {}) {
  if (!navigator.mediaDevices?.getUserMedia) throw Object.assign(new Error('no camera'), { code: 'no_camera' })

  const stream = await openStream(deviceId)
  video.srcObject = stream
  video.setAttribute('playsinline', 'true')
  video.muted = true
  await video.play().catch(() => {})

  const track = stream.getVideoTracks()[0]
  let stopped = false
  let lastCode = null
  let stopEngine = () => {}
  let engine = 'BarcodeDetector'

  const report = (raw) => {
    const isbn = normalizeIsbn(raw)
    if (!isbn || stopped || isbn === lastCode) return
    lastCode = isbn
    navigator.vibrate?.(60)
    onIsbn(isbn)
  }

  const nativeFormats = 'BarcodeDetector' in window ? await window.BarcodeDetector.getSupportedFormats().catch(() => []) : []
  if (nativeFormats.includes('ean_13')) {
    const detector = new window.BarcodeDetector({ formats: ['ean_13'] })
    let timer = null
    const tick = async () => {
      if (stopped) return
      try {
        if (video.readyState >= 2) {
          const codes = await detector.detect(video)
          for (const code of codes) report(code.rawValue)
        }
      } catch {
        /* frame not ready */
      }
      timer = setTimeout(tick, 120)
    }
    tick()
    stopEngine = () => clearTimeout(timer)
  } else {
    engine = 'ZXing'
    const [{ BrowserMultiFormatReader }, { DecodeHintType, BarcodeFormat }] = await Promise.all([
      import('@zxing/browser'),
      import('@zxing/library'),
    ])
    const hints = new Map([
      [DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.EAN_13]],
      [DecodeHintType.TRY_HARDER, true],
    ])
    const reader = new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: 100 })
    const controls = await reader.decodeFromStream(stream, video, (result) => {
      if (result) report(result.getText())
    })
    stopEngine = () => controls.stop()
  }

  const capabilities = track?.getCapabilities?.() ?? {}
  let torchOn = false
  const activeDeviceId = track?.getSettings?.().deviceId ?? deviceId ?? null
  if (activeDeviceId) rememberCamera(activeDeviceId)

  return {
    engine,
    deviceId: activeDeviceId,
    hasTorch: Boolean(capabilities.torch),
    async toggleTorch() {
      torchOn = !torchOn
      await track.applyConstraints({ advanced: [{ torch: torchOn }] }).catch(() => {})
      return torchOn
    },
    /** Allow the same ISBN to be reported again (after the user handled it). */
    reset() {
      lastCode = null
    },
    stop() {
      stopped = true
      stopEngine()
      stream.getTracks().forEach((t) => t.stop())
      video.srcObject = null
    },
  }
}
