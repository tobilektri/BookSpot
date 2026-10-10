/**
 * Short confirmation sounds for the bulk scanner (Web Audio, no audio files).
 * Browsers only allow audio after a user gesture: call unlockAudio() from a click.
 */
let context = null

export function unlockAudio() {
  try {
    context ??= new (window.AudioContext || window.webkitAudioContext)()
    if (context.state === 'suspended') context.resume()
  } catch {
    context = null
  }
}

const PATTERNS = {
  ok: { tones: [[1046, 0.09]], vibrate: 60 },
  warn: { tones: [[660, 0.08], [660, 0.08]], vibrate: [40, 60, 40] },
  error: { tones: [[220, 0.22]], vibrate: [120, 60, 120] },
}

export function beep(kind = 'ok', { sound = true } = {}) {
  const pattern = PATTERNS[kind] ?? PATTERNS.ok
  try {
    navigator.vibrate?.(pattern.vibrate)
  } catch {
    /* not supported */
  }
  if (!sound || !context || context.state !== 'running') return
  let at = context.currentTime
  for (const [frequency, duration] of pattern.tones) {
    const osc = context.createOscillator()
    const gain = context.createGain()
    osc.type = 'sine'
    osc.frequency.value = frequency
    gain.gain.setValueAtTime(0.0001, at)
    gain.gain.exponentialRampToValueAtTime(0.25, at + 0.01)
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration)
    osc.connect(gain).connect(context.destination)
    osc.start(at)
    osc.stop(at + duration + 0.02)
    at += duration + 0.05
  }
}
