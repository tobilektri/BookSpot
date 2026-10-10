<script setup>
import { ref, computed, onUnmounted, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Camera, CameraOff, Flashlight, Keyboard, Search, SwitchCamera, ScanLine, Layers, MapPin, Volume2, VolumeX, Check } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { books as catalog, loadCatalog } from '../lib/catalog.js'
import { normalizeIsbn } from '../lib/isbn.js'
import { startScanner, listCameras } from '../lib/scanner.js'
import { loadShelves, shelves, compartmentName } from '../lib/shelves.js'
import { addScan, loadScans, scanCounts } from '../lib/batch.js'
import { beep, unlockAudio } from '../lib/beep.js'
import LocationPicker from '../components/LocationPicker.vue'
import BatchScanList from '../components/BatchScanList.vue'

const router = useRouter()
const video = ref(null)
const scanner = ref(null)
const starting = ref(false)
const cameraError = ref('')
const status = ref('')
const manual = ref('')
const manualError = ref('')
const torch = ref(false)
const cameras = ref([])
const switching = ref(false)
const secure = window.isSecureContext

// ---- mode: single scan (look up / add one book) or bulk scan into a compartment
function remembered(key, fallback = null) {
  try {
    return localStorage.getItem(key) ?? fallback
  } catch {
    return fallback
  }
}
function remember(key, value) {
  try {
    localStorage.setItem(key, value)
  } catch {
    /* ignore */
  }
}
function rememberedLocation() {
  try {
    const value = JSON.parse(remembered('bookspot.batch.location', 'null'))
    if (value && typeof value === 'object') return value
  } catch {
    /* ignore */
  }
  return { shelf_id: null, shelf_col: null, shelf_row: null }
}
const mode = ref(remembered('bookspot.scan.mode') === 'batch' ? 'batch' : 'single')
const batchLocation = ref(rememberedLocation())
const sound = ref(remembered('bookspot.batch.sound') !== 'off')
const moveExisting = ref(remembered('bookspot.batch.move') !== 'off')
const pickerOpen = ref(false)
const flash = ref(null) // { kind: 'ok' | 'warn' | 'error', text }
let flashTimer = null

const locationReady = computed(() => Boolean(batchLocation.value?.shelf_id && batchLocation.value?.shelf_col && batchLocation.value?.shelf_row))
const locationLabel = computed(() => {
  const shelf = shelves.value.find((s) => s.id === batchLocation.value?.shelf_id)
  if (!shelf) return ''
  return [shelf.name, compartmentName(shelf, batchLocation.value.shelf_col, batchLocation.value.shelf_row)].filter(Boolean).join(' › ')
})

watch(mode, (value) => {
  remember('bookspot.scan.mode', value)
  unlockAudio() // the mode switch is a user gesture: allow the confirmation sound
  status.value = ''
  if (value === 'batch') {
    loadScans()
    pickerOpen.value = !locationReady.value
  }
})
watch(batchLocation, (value) => {
  remember('bookspot.batch.location', JSON.stringify(value))
  if (locationReady.value) {
    pickerOpen.value = false
    // A book that was rejected for lack of a compartment can be scanned again right away.
    scanner.value?.reset()
  }
})
watch(sound, (value) => {
  remember('bookspot.batch.sound', value ? 'on' : 'off')
  if (value) unlockAudio()
})
watch(moveExisting, (value) => remember('bookspot.batch.move', value ? 'on' : 'off'))

function showFlash(kind, text) {
  flash.value = { kind, text }
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 1400)
}

/** Bulk scan: store and continue – no waiting for the metadata. */
async function handleBatchIsbn(isbn) {
  if (!locationReady.value) {
    beep('error', { sound: sound.value })
    showFlash('error', t('batch.chooseFirst'))
    pickerOpen.value = true
    return
  }
  const { duplicate } = await addScan(isbn, batchLocation.value, { label: locationLabel.value, moveExisting: moveExisting.value })
  beep(duplicate ? 'warn' : 'ok', { sound: sound.value })
  showFlash(duplicate ? 'warn' : 'ok', duplicate ? t('batch.alreadyScanned', { isbn }) : isbn)
}

onMounted(() => {
  loadCatalog().catch(() => {})
  loadShelves().catch(() => {})
  if (mode.value === 'batch') {
    loadScans()
    pickerOpen.value = !locationReady.value
  }
  if (secure && navigator.mediaDevices?.getUserMedia) start()
})
// Starting the camera takes a moment (permission prompt, loading ZXing). If the page is left
// in the meantime, the scanner must not keep running in the background.
let unmounted = false
onUnmounted(() => {
  unmounted = true
  clearTimeout(flashTimer)
  stop()
})

async function start(deviceId) {
  cameraError.value = ''
  starting.value = true
  try {
    const started = await startScanner(video.value, handleIsbn, deviceId ? { deviceId } : undefined)
    if (unmounted) {
      started.stop()
      return
    }
    scanner.value = started
    // Device names are only readable once the camera permission was granted.
    cameras.value = await listCameras()
  } catch (e) {
    console.warn(e)
    cameraError.value = t('scan.noCamera')
  } finally {
    starting.value = false
  }
}

/** Next camera in the list (button) or a specific one (select). */
async function switchCamera(deviceId = null) {
  if (switching.value || cameras.value.length < 2) return
  const ids = cameras.value.map((c) => c.deviceId)
  const current = ids.indexOf(scanner.value?.deviceId)
  const target = deviceId ?? ids[(current + 1) % ids.length]
  if (target === scanner.value?.deviceId) return
  switching.value = true
  stop()
  await start(target)
  switching.value = false
}

function cameraLabel(camera, index) {
  return camera.label || t('scan.cameraN', { n: index + 1 })
}

const currentCameraLabel = computed(() => {
  const index = Math.max(0, cameras.value.findIndex((c) => c.deviceId === scanner.value?.deviceId))
  return cameras.value[index] ? cameraLabel(cameras.value[index], index) : ''
})

function stop() {
  scanner.value?.stop()
  scanner.value = null
  torch.value = false
}

async function toggleTorch() {
  torch.value = await scanner.value?.toggleTorch()
}

/** Catalogue lookup: found -> detail page, otherwise -> create dialog with metadata lookup. */
async function handleIsbn(isbn) {
  if (mode.value === 'batch') return handleBatchIsbn(isbn)
  status.value = t('scan.searching', { isbn })
  try {
    const result = await api(`v1/books/isbn/${isbn}`)
    stop()
    if (result.found) router.push(`/books/${result.book.id}`)
    else router.push({ name: 'book-new', query: { isbn } })
  } catch (e) {
    if (e.offline) {
      // Offline: search the cached catalogue.
      const hit = catalog.value.find((b) => b.isbn13 === isbn)
      stop()
      if (hit) router.push(`/books/${hit.id}`)
      else router.push({ name: 'book-new', query: { isbn } })
      return
    }
    status.value = errorMessage(e)
    scanner.value?.reset()
  }
}

function submitManual() {
  const isbn = normalizeIsbn(manual.value)
  manualError.value = isbn ? '' : t('scan.invalid')
  if (isbn) {
    unlockAudio()
    handleIsbn(isbn)
    if (mode.value === 'batch') manual.value = ''
  }
}

function startWithSound() {
  unlockAudio()
  start()
}
</script>

<template>
  <div class="mx-auto max-w-xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-serif text-2xl font-semibold">{{ t('scan.title') }}</h1>
      <div class="inline-flex rounded-xl bg-stone-100 p-1 text-sm dark:bg-stone-800" role="radiogroup" :aria-label="t('batch.mode')">
        <button
          v-for="option in [{ value: 'single', icon: ScanLine, label: t('batch.single') }, { value: 'batch', icon: Layers, label: t('batch.bulk') }]"
          :key="option.value"
          type="button"
          role="radio"
          :aria-checked="mode === option.value"
          class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium transition"
          :class="mode === option.value ? 'bg-white shadow-sm dark:bg-stone-900' : 'text-stone-500'"
          @click="mode = option.value"
        >
          <component :is="option.icon" class="h-4 w-4" />{{ option.label }}
        </button>
      </div>
    </div>

    <!-- bulk scan: compartment for all scans of this session -->
    <section v-if="mode === 'batch'" class="card space-y-3">
      <div v-if="locationReady && !pickerOpen" class="flex items-center gap-3">
        <MapPin class="h-5 w-5 shrink-0 text-brand-600" />
        <div class="min-w-0 flex-1">
          <div class="text-xs text-stone-500">{{ t('batch.target') }}</div>
          <div class="truncate font-semibold">{{ locationLabel }}</div>
        </div>
        <button type="button" class="btn-secondary !py-1.5 text-sm" @click="pickerOpen = true">{{ t('batch.change') }}</button>
      </div>
      <template v-else>
        <p class="text-sm text-stone-600 dark:text-stone-400">{{ t('batch.chooseHint') }}</p>
        <LocationPicker v-model="batchLocation" />
      </template>
      <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
        <label class="flex items-center gap-2"><input v-model="moveExisting" type="checkbox" class="h-4 w-4 accent-brand-600" />{{ t('batch.moveExisting') }}</label>
        <label class="flex items-center gap-2">
          <input v-model="sound" type="checkbox" class="h-4 w-4 accent-brand-600" />
          <component :is="sound ? Volume2 : VolumeX" class="h-4 w-4" />{{ t('batch.sound') }}
        </label>
      </div>
    </section>

    <div class="relative aspect-[3/4] overflow-hidden rounded-2xl bg-stone-900 sm:aspect-video">
      <video ref="video" class="h-full w-full object-cover" playsinline muted></video>
      <div v-if="scanner" class="pointer-events-none absolute inset-0 flex items-center justify-center">
        <div class="scanner-frame relative h-32 w-4/5 max-w-sm rounded-xl border-2 border-white/90">
          <div class="absolute inset-x-3 top-1/2 h-0.5 animate-pulse bg-red-500/80"></div>
        </div>
      </div>
      <div v-if="!scanner" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-stone-200">
        <CameraOff class="h-10 w-10 opacity-70" />
        <p v-if="!secure" class="text-sm">{{ t('scan.insecure') }}</p>
        <p v-else-if="cameraError" class="text-sm">{{ cameraError }}</p>
        <button v-if="secure" class="btn-primary" :disabled="starting" @click="startWithSound"><Camera class="h-4 w-4" />{{ t('scan.start') }}</button>
      </div>
      <!-- bulk scan feedback -->
      <Transition name="flash">
        <div v-if="flash" class="pointer-events-none absolute inset-x-0 top-3 flex justify-center px-3">
          <div
            class="flex max-w-full items-center gap-2 truncate rounded-full px-4 py-2 text-sm font-semibold shadow-lg"
            :class="{ 'bg-emerald-500 text-white': flash.kind === 'ok', 'bg-amber-400 text-black': flash.kind === 'warn', 'bg-red-600 text-white': flash.kind === 'error' }"
          >
            <Check v-if="flash.kind === 'ok'" class="h-4 w-4 shrink-0" />{{ flash.text }}
          </div>
        </div>
      </Transition>
      <div v-if="scanner" class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-gradient-to-t from-black/70 p-3 text-xs text-white">
        <span v-if="mode === 'batch'" class="font-medium" aria-live="polite">{{ t('batch.counterShort', { total: scanCounts.total, waiting: scanCounts.waiting }) }}</span>
        <span v-else>{{ status || t('scan.hint') }}</span>
        <div class="flex gap-2">
          <button
            v-if="cameras.length > 1"
            class="rounded-full bg-white/20 p-2"
            :disabled="switching"
            :aria-label="t('scan.switchCamera')"
            :title="t('scan.switchCamera')"
            @click="switchCamera()"
          >
            <SwitchCamera class="h-4 w-4" :class="{ 'animate-spin': switching }" />
          </button>
          <button v-if="scanner.hasTorch" class="rounded-full bg-white/20 p-2" :class="{ 'bg-amber-400 text-black': torch }" :aria-label="t('scan.torch')" @click="toggleTorch">
            <Flashlight class="h-4 w-4" />
          </button>
          <button class="rounded-full bg-white/20 p-2" :aria-label="t('scan.stop')" @click="stop"><CameraOff class="h-4 w-4" /></button>
        </div>
      </div>
    </div>
    <div v-if="scanner" class="flex flex-wrap items-center justify-center gap-2 text-xs text-stone-500">
      <select
        v-if="cameras.length > 2"
        :value="scanner.deviceId"
        class="input !w-auto !py-1 !text-xs"
        :aria-label="t('scan.chooseCamera')"
        @change="switchCamera($event.target.value)"
      >
        <option v-for="(camera, i) in cameras" :key="camera.deviceId" :value="camera.deviceId">{{ cameraLabel(camera, i) }}</option>
      </select>
      <span v-else-if="cameras.length > 1">{{ currentCameraLabel }}</span>
      <span>{{ t('scan.engine', { name: scanner.engine }) }}</span>
    </div>

    <form class="card space-y-2" @submit.prevent="submitManual">
      <label class="label flex items-center gap-2" for="manual_isbn"><Keyboard class="h-4 w-4" />{{ t('scan.manual') }}</label>
      <div class="flex gap-2">
        <input id="manual_isbn" v-model="manual" class="input" inputmode="numeric" autocomplete="off" :placeholder="t('scan.manualPlaceholder')" />
        <button class="btn-primary shrink-0"><Search class="h-4 w-4" />{{ t('scan.go') }}</button>
      </div>
      <p v-if="manualError" class="field-error">{{ manualError }}</p>
      <p v-else-if="status && !scanner" class="text-sm text-stone-500">{{ status }}</p>
    </form>

    <BatchScanList v-if="mode === 'batch'" />
  </div>
</template>

<style scoped>
.flash-enter-active,
.flash-leave-active {
  transition: all 0.2s ease;
}
.flash-enter-from,
.flash-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}
</style>
