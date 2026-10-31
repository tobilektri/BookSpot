/**
 * Scales a photo down (longest side ≤ max) and re-encodes it as JPEG before
 * uploading: a phone photo has several MB, shared hosting often accepts only 2 MB.
 * Uses createImageBitmap (no blob: URL, which the Content-Security-Policy blocks);
 * when the browser cannot decode the file, the original is sent.
 */
export async function shrinkImage(file, max = 1600, quality = 0.85) {
  if (typeof createImageBitmap !== 'function') return file
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(bitmap.width * scale))
    canvas.height = Math.max(1, Math.round(bitmap.height * scale))
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#fff' // transparent PNGs would turn black as JPEG
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    bitmap.close?.()
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
    return blob ?? file
  } catch {
    return file
  }
}
