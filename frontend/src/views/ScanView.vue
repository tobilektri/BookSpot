<script setup>
import { ref, computed, onUnmounted, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Camera, CameraOff, Flashlight, Keyboard, Search, SwitchCamera } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { books as catalog, loadCatalog } from '../lib/catalog.js'
import { normalizeIsbn } from '../lib/isbn.js'
import { startScanner, listCameras } from '../lib/scanner.js'

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

onMounted(() => {
  loadCatalog().catch(() => {})
  if (secure && navigator.mediaDevices?.getUserMedia) start()
})
onUnmounted(stop)

async function start(deviceId) {
  cameraError.value = ''
  starting.value = true
  try {
    scanner.value = await startScanner(video.value, handleIsbn, deviceId ? { deviceId } : undefined)
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
  status.value = t('scan.searching', { isbn })
  try {
    const result = await api('books.php', { query: { isbn } })
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
  if (isbn) handleIsbn(isbn)
}
</script>

<template>
  <div class="mx-auto max-w-xl space-y-4">
    <h1 class="font-serif text-2xl font-semibold">{{ t('scan.title') }}</h1>

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
        <button v-if="secure" class="btn-primary" :disabled="starting" @click="start"><Camera class="h-4 w-4" />{{ t('scan.start') }}</button>
      </div>
      <div v-if="scanner" class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-gradient-to-t from-black/70 p-3 text-xs text-white">
        <span>{{ status || t('scan.hint') }}</span>
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
  </div>
</template>
