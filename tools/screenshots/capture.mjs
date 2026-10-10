/**
 * Screenshots and GIFs for README.md / docs/ – reproducible with the demo data.
 *
 *   # 1. fresh demo instance (SQLite, separate from your data)
 *   export DB_DRIVER=sqlite DB_SQLITE_PATH=/tmp/bookspot-demo-en.sqlite METADATA_DNB=false
 *   php backend/bin/demo-data.php en
 *   php -S localhost:8000 -t public backend/bin/dev-router.php &
 *   # 2. capture (same environment, so fill-queue.php uses the demo database)
 *   cd tools/screenshots && npm install && LOCALE=en node capture.mjs
 *
 * Needs ffmpeg for the GIFs. Output: docs/images/<name>-<locale>.png|gif
 */
import { mkdirSync, writeFileSync, rmSync, readdirSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { tmpdir } from 'node:os'

const { chromium } = await import('playwright').catch(() => import('playwright-core'))

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../..')
const BASE = process.env.BASE_URL ?? 'http://localhost:8000/'
const LOCALE = process.env.LOCALE === 'de' ? 'de' : 'en'
const OUT = resolve(process.env.OUT ?? join(root, 'docs/images'))
const executablePath = process.env.CHROMIUM_PATH || undefined
const work = join(tmpdir(), `bookspot-shots-${process.pid}`)
mkdirSync(OUT, { recursive: true })
mkdirSync(work, { recursive: true })

const T = {
  en: { hello: 'Hi anna!', batch: 'Batch', single: 'Single', shelves: 'Shelves', moveComp: (n) => `Move compartment ${n}`, living: 'Living room' },
  de: { hello: 'Hallo anna!', batch: 'Stapel', single: 'Einzeln', shelves: 'Regale', moveComp: (n) => `Fach ${n} verschieben`, living: 'Wohnzimmer' },
}[LOCALE]

const DESKTOP = { width: 1280, height: 800 }
const PHONE = { width: 390, height: 844 }
const quiet = () => {
  // No install/push prompts in the pictures.
  const later = JSON.stringify({ count: 1, next: Date.now() + 1e10 })
  localStorage.setItem('bookspot.nudge.install', later)
  localStorage.setItem('bookspot.nudge.push', later)
}

async function newPage(browser, { viewport, colorScheme = 'light', video = null, args = {} } = {}) {
  const context = await browser.newContext({
    viewport,
    deviceScaleFactor: 2,
    colorScheme,
    locale: LOCALE === 'de' ? 'de-DE' : 'en-US',
    permissions: ['camera'],
    ...(video ? { recordVideo: { dir: video, size: viewport } } : {}),
    ...args,
  })
  await context.addInitScript(quiet)
  const page = await context.newPage()
  await page.goto(BASE)
  await page.fill('#login', 'anna')
  await page.fill('#password', 'demo-password')
  await page.click('button.btn-primary')
  await page.waitForSelector(`text=${T.hello}`)
  await page.waitForTimeout(600)
  return { context, page }
}

async function shot(page, name, { wait = 700 } = {}) {
  await page.waitForTimeout(wait) // charts and transitions
  const file = join(OUT, `${name}-${LOCALE}.png`)
  await page.screenshot({ path: file })
  try {
    // 256 colours are plenty for UI screenshots and halve the file size (ImageMagick, optional).
    execFileSync('convert', [file, '-dither', 'None', '-colors', '256', `PNG8:${file}`])
  } catch {
    /* keep the full-colour PNG */
  }
  console.log('  ', file.replace(root + '/', ''))
}

function toGif(webm, name, width) {
  const file = join(OUT, `${name}-${LOCALE}.gif`)
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', webm, '-vf',
    `fps=8,scale=${width}:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=4:diff_mode=rectangle`, file])
  console.log('  ', file.replace(root + '/', ''))
}

// ---- fake camera: EAN-13 barcodes one after another (YUV4MPEG2, looped by Chrome)
function ean13(code) {
  const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011']
  const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111']
  const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100']
  const P = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL']
  const parity = P[+code[0]]
  let bits = '101'
  for (let i = 1; i < 7; i++) bits += (parity[i - 1] === 'L' ? L : G)[+code[i]]
  bits += '01010'
  for (let i = 7; i < 13; i++) bits += R[+code[i]]
  return bits + '101'
}

function fakeCamera(codes) {
  const W = 640, H = 480, FPS = 10, module = 4
  const frames = []
  const blank = () => { const y = Buffer.alloc(W * H, 205); return y }
  const barcode = (code) => {
    const y = Buffer.alloc(W * H, 235)
    const bits = ean13(code)
    const x0 = Math.floor((W - bits.length * module) / 2)
    for (let i = 0; i < bits.length; i++) {
      if (bits[i] !== '1') continue
      for (let x = x0 + i * module; x < x0 + (i + 1) * module; x++) for (let row = 150; row < 330; row++) y[row * W + x] = 16
    }
    return y
  }
  for (const code of codes) {
    for (let i = 0; i < FPS * 1.2; i++) frames.push(blank())
    const frame = barcode(code)
    for (let i = 0; i < FPS * 1.6; i++) frames.push(frame)
  }
  for (let i = 0; i < FPS * 60; i++) frames.push(blank()) // nothing to scan after the last book
  const chroma = Buffer.alloc((W / 2) * (H / 2) * 2, 128)
  const parts = [Buffer.from(`YUV4MPEG2 W${W} H${H} F${FPS}:1 Ip A1:1 C420jpeg\n`)]
  for (const y of frames) parts.push(Buffer.from('FRAME\n'), y, chroma)
  const file = join(work, 'camera.y4m')
  writeFileSync(file, Buffer.concat(parts))
  return file
}

/** Valid ISBN-13 like demo-data.php (978-3-00-9xxxxx). */
function isbn(n) {
  const digits = '978300' + String(900000 + n).padStart(6, '0')
  let sum = 0
  ;[...digits].forEach((d, i) => (sum += +d * (i % 2 ? 3 : 1)))
  return digits + ((10 - (sum % 10)) % 10)
}

const php = (...args) => execFileSync('php', [join(here, 'fill-queue.php'), ...args], { env: process.env }).toString().trim()

// ----------------------------------------------------------------------------------------------
const browser = await chromium.launch({ executablePath })
console.log(`Screenshots (${LOCALE}) -> ${OUT.replace(root + '/', '')}`)

{
  const { page, context } = await newPage(browser, { viewport: DESKTOP })
  await shot(page, 'dashboard')
  await page.goto(BASE + '#/books'); await shot(page, 'books')
  const book = page.locator('main a[href^="#/books/"]:not([href="#/books/new"])', { hasText: LOCALE === 'de' ? 'Anna Karenina' : 'Anna Karenina' }).first()
  await page.fill('main input', 'Karenina'); await page.waitForTimeout(400); await book.click()
  await page.waitForSelector('main h1'); await shot(page, 'book-detail')
  await page.goto(BASE + '#/shelves'); await page.waitForTimeout(400)
  await page.locator('main a[href^="#/shelves/"]', { hasText: T.living }).first().click(); await shot(page, 'shelf')
  // Tall viewport instead of fullPage: resizing during the screenshot would re-animate the charts.
  await page.setViewportSize({ width: DESKTOP.width, height: 1560 })
  await page.goto(BASE + '#/stats'); await shot(page, 'stats', { wait: 2500 })
  await page.setViewportSize(DESKTOP)
  await page.goto(BASE + '#/settings#users'); await page.waitForTimeout(800); await shot(page, 'settings')
  await context.close()
}

{
  const { page, context } = await newPage(browser, { viewport: PHONE })
  await shot(page, 'mobile-dashboard')
  await page.goto(BASE + '#/books'); await shot(page, 'mobile-books')
  await page.goto(BASE + '#/settings'); await page.waitForTimeout(300)
  await page.locator('button', { hasText: LOCALE === 'de' ? 'Einführung erneut ansehen' : 'Show introduction again' }).click()
  await page.waitForSelector('[role=dialog]'); await shot(page, 'mobile-wizard')
  await context.close()
}

{
  const { page, context } = await newPage(browser, { viewport: PHONE, colorScheme: 'dark' })
  await page.goto(BASE + '#/books'); await shot(page, 'mobile-books-dark')
  await context.close()
}

// ---- GIF: shelf editor – drag a compartment to another column
{
  const video = join(work, 'shelf')
  const { page, context } = await newPage(browser, { viewport: DESKTOP, video })
  await page.goto(BASE + '#/shelves'); await page.waitForTimeout(400)
  const href = await page.locator('main a[href^="#/shelves/"]', { hasText: T.living }).first().getAttribute('href')
  await page.goto(BASE + href + '/edit'); await page.waitForSelector('[data-col]'); await page.waitForTimeout(800)
  const drag = async (name, col, row, dx = 60) => {
    const handle = await page.locator(`button[aria-label="${T.moveComp(name)}"]`).boundingBox()
    const target = await page.locator(`[data-col="${col}"][data-row="${row}"]`).boundingBox()
    await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2)
    await page.mouse.down()
    await page.mouse.move(target.x + dx, target.y + 6, { steps: 25 })
    await page.waitForTimeout(500)
    await page.mouse.up()
    await page.waitForTimeout(900)
  }
  await drag('M5', 2, 2)
  await drag('L4', 1, 0)
  await page.waitForTimeout(1200)
  await context.close()
  toGif(join(video, readdirSync(video)[0]), 'shelf-editor', 960)
}

// ---- GIF: bulk scan on the phone with a fake camera
{
  php('--block-providers') // demo: no real lookups, fill-queue.php plays the metadata source
  const camera = fakeCamera([isbn(101), isbn(102), isbn(4), isbn(103)]) // isbn(4) is already in the catalogue
  const scanBrowser = await chromium.launch({
    executablePath,
    args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-video-capture=${camera}`],
  })
  const video = join(work, 'scan')
  const { page, context } = await newPage(scanBrowser, { viewport: PHONE, video })
  await page.goto(BASE + '#/scan')
  await page.click(`button[role=radio]:has-text("${T.batch}")`)
  const shelfSelect = page.locator('section.card select').first()
  await shelfSelect.selectOption({ label: T.living })
  await page.locator('section.card select').nth(1).selectOption({ label: 'M2' })
  await page.waitForTimeout(13_000) // four books pass the camera
  await page.evaluate(() => window.scrollTo({ top: 520, behavior: 'smooth' }))
  await page.waitForTimeout(1500)
  console.log('   queue:', php('--fill'))
  await page.waitForTimeout(6000) // the app picks up the titles
  await shot(page, 'mobile-bulk-scan')
  await context.close()
  await scanBrowser.close()
  toGif(join(video, readdirSync(video)[0]), 'bulk-scan', 360)
}

await browser.close()
rmSync(work, { recursive: true, force: true })
