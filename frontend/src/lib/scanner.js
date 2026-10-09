import { normalizeIsbn } from './isbn.js'

/**
 * Camera barcode scanner. Uses the native BarcodeDetector API where available
 * (Chrome/Android, Safari 17+) and falls back to ZXing (lazy-loaded) elsewhere.
 * Only valid ISBN-13/EAN codes starting with 978/979 are reported.
 */
export async function startScanner(video, onIsbn) {
  if (!navigator.mediaDevices?.getUserMedia) throw Object.assign(new Error('no camera'), { code: 'no_camera' })

  const stream = await navigator.mediaDevices.getUserMedia({
    audio: false,
    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
  })
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

  return {
    engine,
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
