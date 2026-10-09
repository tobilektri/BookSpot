<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { MonitorDown, Smartphone, BellRing, X } from '@lucide/vue'
import { store, toast } from '../lib/store.js'
import { t, errorMessage, appName } from '../lib/i18n.js'
import { install, installMethod, promptInstall, nudgeDue, snoozeNudge, resetNudge, platform } from '../lib/engagement.js'
import { pushState, refreshPushState, pushOfferable, pushNeedsInstall, enablePush } from '../lib/push.js'
import InstallGuide from './InstallGuide.vue'
import PushUnblockHelp from './PushUnblockHelp.vue'
import BaseModal from './BaseModal.vue'

/**
 * Bottom sheet that actively offers installing the app and enabling push.
 * Shown a few seconds after opening an overview page, at most one prompt per
 * visit; "Later" snoozes with growing intervals until the user accepts.
 * Creating a loan triggers the push offer right away (store.pushOffer).
 */
const route = useRoute()
const sheet = ref(null) // 'install' | 'push' | 'push-denied' | null
const reason = ref('') // 'loan' when triggered by a new loan
const showGuide = ref(false)
const busy = ref(false)
let shownThisVisit = false
let timer = null

const PAGES = ['home', 'books', 'shelves', 'stats', 'book', 'shelf']

onMounted(() => {
  if (store.user) refreshPushState()
})
watch(
  () => store.user?.id,
  (id) => id && refreshPushState(),
)

function schedule() {
  clearTimeout(timer)
  if (!store.user || shownThisVisit || sheet.value || !PAGES.includes(route.name)) return
  timer = setTimeout(pick, 3500)
}
watch([() => route.name, () => store.user?.id, () => install.promptEvent, () => pushState.loaded], schedule, { immediate: true })

function pick() {
  if (!store.user || !PAGES.includes(route.name)) return
  // Desktop Chrome/Edge without install event is usually already installed – no nagging there.
  const method = installMethod()
  if (method && method !== 'desktop-chromium' && nudgeDue('install')) return open('install')
  if (pushOfferable() && nudgeDue('push')) return open(pushState.permission === 'denied' ? 'push-denied' : 'push')
}

function open(kind, why = '') {
  sheet.value = kind
  reason.value = why
  shownThisVisit = true
}

// Contextual offer, e.g. right after creating a library loan.
watch(
  () => store.pushOffer,
  (why) => {
    if (!why) return
    store.pushOffer = null
    if (pushState.subscribed || sheet.value) return
    try {
      const last = Number(localStorage.getItem('bookspot.nudge.pushContext') || 0)
      if (Date.now() - last < 86_400_000) return
      localStorage.setItem('bookspot.nudge.pushContext', String(Date.now()))
    } catch {
      /* ignore */
    }
    if (pushNeedsInstall() && installMethod()) open('install', why)
    else if (pushOfferable()) open(pushState.permission === 'denied' ? 'push-denied' : 'push', why)
  },
)

function later() {
  const key = sheet.value === 'install' ? 'install' : 'push'
  const days = snoozeNudge(key)
  sheet.value = null
  toast(t('engage.snoozed', { n: days }), 'info')
}

async function doInstall() {
  busy.value = true
  const outcome = await promptInstall()
  busy.value = false
  if (outcome === 'accepted') {
    resetNudge('install')
    sheet.value = null
    toast(t('engage.installed'), 'success')
  } else if (outcome === 'dismissed') {
    later()
  }
}

async function doEnablePush() {
  busy.value = true
  try {
    await enablePush()
    resetNudge('push')
    sheet.value = null
    toast(t('settings.pushEnabled'), 'success')
  } catch (e) {
    if (e.code === 'denied') sheet.value = 'push-denied'
    else toast(errorMessage(e), 'error')
  } finally {
    busy.value = false
  }
}

/** iOS guide closed: installing happens outside the app, so just ask again later. */
function guideDone() {
  showGuide.value = false
  sheet.value = null
  snoozeNudge('install')
}

const method = computed(() => installMethod())
const name = computed(() => store.appName || appName.value)
</script>

<template>
  <Transition name="sheet">
    <aside
      v-if="sheet"
      class="fixed inset-x-3 bottom-24 z-40 mx-auto max-w-md rounded-2xl border border-stone-200 bg-white p-4 shadow-2xl md:inset-x-auto md:right-6 md:bottom-6 dark:border-stone-700 dark:bg-stone-900"
      role="dialog"
      :aria-label="sheet === 'install' ? t('engage.installTitle', { name }) : t('pushOffer.title')"
    >
      <button class="absolute top-2 right-2 rounded-full p-1.5 text-stone-400 hover:bg-stone-100 dark:hover:bg-stone-800" :aria-label="t('engage.later')" @click="later">
        <X class="h-4 w-4" />
      </button>

      <!-- Install -->
      <template v-if="sheet === 'install'">
        <div class="flex gap-3 pr-6">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
            <component :is="platform.ios || platform.android ? Smartphone : MonitorDown" class="h-5 w-5" />
          </span>
          <div>
            <h2 class="font-semibold">{{ t('engage.installTitle', { name }) }}</h2>
            <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">
              {{ reason === 'loan' ? t('engage.installForPush') : t('engage.installText') }}
            </p>
          </div>
        </div>
        <InstallGuide v-if="method === 'android-menu' || method === 'mac-safari'" :method="method" class="mt-3" />
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-ghost" @click="later">{{ t('engage.later') }}</button>
          <button v-if="method === 'prompt'" class="btn-primary" :disabled="busy" @click="doInstall">{{ t('engage.install') }}</button>
          <button v-else-if="method === 'ios' || method === 'ios-inapp'" class="btn-primary" @click="showGuide = true">{{ t('engage.showGuide') }}</button>
        </div>
      </template>

      <!-- Push -->
      <template v-else-if="sheet === 'push'">
        <div class="flex gap-3 pr-6">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
            <BellRing class="h-5 w-5" />
          </span>
          <div>
            <h2 class="font-semibold">{{ t('pushOffer.title') }}</h2>
            <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ reason === 'loan' ? t('pushOffer.loanText') : t('pushOffer.text') }}</p>
          </div>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-ghost" @click="later">{{ t('engage.later') }}</button>
          <button class="btn-primary" :disabled="busy" @click="doEnablePush"><BellRing class="h-4 w-4" />{{ t('pushOffer.enable') }}</button>
        </div>
      </template>

      <!-- Push blocked in the browser: explain how to allow it -->
      <template v-else-if="sheet === 'push-denied'">
        <h2 class="pr-6 font-semibold">{{ t('pushOffer.deniedTitle') }}</h2>
        <PushUnblockHelp class="mt-2" />
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-ghost" @click="later">{{ t('engage.later') }}</button>
          <button class="btn-primary" @click="doEnablePush">{{ t('pushOffer.retry') }}</button>
        </div>
      </template>
    </aside>
  </Transition>

  <BaseModal v-if="showGuide" :title="t('install.title', { name })" @close="showGuide = false">
    <InstallGuide :method="method" />
    <div class="mt-4 flex justify-end">
      <button class="btn-primary" @click="guideDone">{{ t('install.done') }}</button>
    </div>
  </BaseModal>
</template>

<style scoped>
.sheet-enter-active,
.sheet-leave-active {
  transition: all 0.25s ease;
}
.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
  transform: translateY(16px);
}
</style>
