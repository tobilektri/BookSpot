<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  Sparkles, ScanBarcode, BookOpen, LibraryBig, Library, CalendarClock, Smartphone, Upload, GripVertical, Users, Clock, PartyPopper, X, ChevronLeft, ChevronRight, BellRing, Check,
} from '@lucide/vue'
import { api } from '../lib/api.js'
import { store, toast } from '../lib/store.js'
import { t, appName, errorMessage, fieldError } from '../lib/i18n.js'
import { rememberUser } from '../lib/session.js'
import { pushState, refreshPushState, pushOfferable, enablePush, pushNeedsInstall } from '../lib/push.js'
import { installMethod, promptInstall, install, resetNudge } from '../lib/engagement.js'
import InstallGuide from './InstallGuide.vue'

/**
 * Introduction shown once per user after the first login (users.onboarded_at)
 * and on demand from the settings (store.showOnboarding). Admins get extra
 * steps about import, shelves, users and cron.
 */
const router = useRouter()
const index = ref(0)
const busy = ref(false)
const showGuide = ref(false)

const isAdmin = computed(() => store.user?.role === 'admin')
const name = computed(() => store.appName || appName.value)
const visible = computed(
  () => Boolean(store.user) && !store.impersonator && (store.showOnboarding || !store.user.onboarded_at),
)

watch(visible, (v) => {
  if (v) {
    index.value = 0
    showGuide.value = false
    refreshPushState()
    resetLibrary()
  }
})

const steps = computed(() => {
  const list = [
    { key: 'welcome', icon: Sparkles },
    { key: 'find', icon: ScanBarcode },
    { key: 'reading', icon: BookOpen },
    { key: 'shelves', icon: LibraryBig },
    { key: 'library', icon: Library, action: 'library' },
    { key: 'loans', icon: CalendarClock, action: 'push' },
    { key: 'install', icon: Smartphone, action: 'install' },
  ]
  if (isAdmin.value) {
    list.push(
      { key: 'adminImport', icon: Upload },
      { key: 'adminShelves', icon: GripVertical },
      { key: 'adminUsers', icon: Users },
      { key: 'adminCron', icon: Clock },
    )
  }
  list.push({ key: 'done', icon: PartyPopper })
  return list
})
const step = computed(() => steps.value[index.value])
const isLast = computed(() => index.value === steps.value.length - 1)

function text(field) {
  return t(`onboarding.${step.value.key}.${field}`, { name: store.user?.username ?? '', app: name.value })
}

async function finish(goTo = null) {
  store.showOnboarding = false
  if (store.user && !store.user.onboarded_at) {
    store.user = { ...store.user, onboarded_at: new Date().toISOString() }
    await api('auth.php', { method: 'POST', query: { action: 'onboarded' } }).catch(() => {})
  }
  if (goTo) router.push(goTo)
}

async function next() {
  if (step.value.action === 'library' && !(await saveLibrary())) return
  if (isLast.value) finish()
  else index.value++
}

// ---- actions inside the wizard (no navigation, the tour continues)

const pushDone = computed(() => pushState.subscribed)
const canEnablePush = computed(() => pushOfferable() && pushState.permission !== 'denied')

async function doEnablePush() {
  busy.value = true
  try {
    await enablePush()
    resetNudge('push')
    toast(t('settings.pushEnabled'), 'success')
  } catch (e) {
    if (e.code !== 'denied') toast(errorMessage(e), 'error')
  } finally {
    busy.value = false
  }
}

// Library defaults (same as Settings → My library), saved when moving on.
const library = reactive({ library_name: '', library_loan_days: '' })
const libraryError = ref('')
function resetLibrary() {
  library.library_name = store.user?.library_name ?? ''
  library.library_loan_days = store.user?.library_loan_days ?? ''
  libraryError.value = ''
}
async function saveLibrary() {
  const unchanged =
    library.library_name.trim() === (store.user?.library_name ?? '') && String(library.library_loan_days) === String(store.user?.library_loan_days ?? '')
  if (unchanged) return true
  busy.value = true
  libraryError.value = ''
  try {
    const { user } = await api('auth.php', { method: 'POST', query: { action: 'profile' }, body: { ...library } })
    rememberUser(user)
    return true
  } catch (e) {
    libraryError.value = e.fields?.library_loan_days ? fieldError(e.fields.library_loan_days) : errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

const method = computed(() => installMethod())

async function doInstall() {
  if (method.value === 'prompt') {
    const outcome = await promptInstall()
    if (outcome === 'accepted') toast(t('engage.installed'), 'success')
  } else {
    showGuide.value = !showGuide.value
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 sm:items-center sm:p-4">
      <div
        class="flex max-h-[92dvh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl dark:bg-stone-900"
        role="dialog"
        aria-modal="true"
        :aria-label="t('onboarding.title')"
      >
        <!-- progress -->
        <div class="flex items-center gap-3 px-5 pt-4">
          <div class="flex flex-1 gap-1" aria-hidden="true">
            <span
              v-for="(s, i) in steps"
              :key="s.key"
              class="h-1.5 flex-1 rounded-full transition"
              :class="i <= index ? 'bg-brand-600' : 'bg-stone-200 dark:bg-stone-700'"
            ></span>
          </div>
          <span class="text-xs text-stone-500 tabular-nums">{{ index + 1 }} / {{ steps.length }}</span>
          <button class="rounded-full p-1.5 text-stone-400 hover:bg-stone-100 dark:hover:bg-stone-800" :aria-label="t('onboarding.skip')" :title="t('onboarding.skip')" @click="finish()">
            <X class="h-4 w-4" />
          </button>
        </div>

        <div class="overflow-y-auto px-5 pt-5 pb-2">
          <div class="flex items-start gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
              <component :is="step.icon" class="h-6 w-6" />
            </span>
            <div class="min-w-0">
              <p v-if="step.key.startsWith('admin')" class="text-xs font-semibold tracking-wide text-brand-700 uppercase dark:text-brand-400">{{ t('onboarding.adminBadge') }}</p>
              <h2 class="text-xl font-semibold">{{ text('title') }}</h2>
            </div>
          </div>
          <p class="mt-4 text-sm leading-relaxed text-stone-700 dark:text-stone-300">{{ text('text') }}</p>
          <ul class="mt-3 space-y-2 text-sm text-stone-700 dark:text-stone-300">
            <li v-for="n in [1, 2, 3]" :key="n" class="flex gap-2">
              <Check class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
              <span>{{ t(`onboarding.${step.key}.p${n}`, { app: name }) }}</span>
            </li>
          </ul>

          <!-- step actions -->
          <div v-if="step.action === 'push'" class="mt-4 rounded-xl bg-stone-100 p-3 text-sm dark:bg-stone-800">
            <p v-if="pushDone" class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400"><Check class="h-4 w-4" />{{ t('settings.pushEnabled') }}</p>
            <p v-else-if="pushNeedsInstall()">{{ t('pushOffer.iosInstallFirst') }}</p>
            <button v-else-if="canEnablePush" class="btn-primary" :disabled="busy" @click="doEnablePush"><BellRing class="h-4 w-4" />{{ t('pushOffer.enable') }}</button>
            <p v-else class="text-stone-500">{{ t('onboarding.loans.pushLater') }}</p>
          </div>
          <div v-if="step.action === 'library'" class="mt-4 grid grid-cols-1 gap-3 rounded-xl bg-stone-100 p-3 text-sm sm:grid-cols-2 dark:bg-stone-800">
            <div>
              <label class="label" for="ob_library_name">{{ t('settings.libraryName') }}</label>
              <input id="ob_library_name" v-model="library.library_name" class="input" maxlength="150" placeholder="Stadtbibliothek" />
            </div>
            <div>
              <label class="label" for="ob_library_loan_days">{{ t('settings.loanDays') }}</label>
              <div class="flex items-center gap-2">
                <input id="ob_library_loan_days" v-model="library.library_loan_days" type="number" min="1" max="365" inputmode="numeric" class="input" placeholder="28" @keydown.enter.prevent="next" />
                <span class="text-stone-500">{{ t('settings.days') }}</span>
              </div>
            </div>
            <p v-if="libraryError" class="field-error sm:col-span-2">{{ libraryError }}</p>
            <p v-else class="text-stone-500 sm:col-span-2">{{ t('onboarding.library.skipHint') }}</p>
          </div>
          <div v-if="step.action === 'install'" class="mt-4 rounded-xl bg-stone-100 p-3 text-sm dark:bg-stone-800">
            <p v-if="install.installed" class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400"><Check class="h-4 w-4" />{{ t('install.alreadyInstalled') }}</p>
            <template v-else-if="method">
              <button class="btn-primary" @click="doInstall">
                <Smartphone class="h-4 w-4" />{{ method === 'prompt' ? t('engage.install') : showGuide ? t('onboarding.install.hideGuide') : t('engage.showGuide') }}
              </button>
              <InstallGuide v-if="showGuide" :method="method" class="mt-3" />
            </template>
            <p v-else class="text-stone-500">{{ t('install.unsupported') }}</p>
          </div>

          <!-- final quick links -->
          <div v-if="isLast" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <button class="btn-secondary" @click="finish('/scan')"><ScanBarcode class="h-4 w-4" />{{ t('onboarding.done.scan') }}</button>
            <button v-if="isAdmin" class="btn-secondary" @click="finish('/settings#import')"><Upload class="h-4 w-4" />{{ t('onboarding.done.import') }}</button>
            <button v-if="isAdmin" class="btn-secondary" @click="finish('/settings#users')"><Users class="h-4 w-4" />{{ t('onboarding.done.users') }}</button>
            <button v-else class="btn-secondary" @click="finish('/books')"><BookOpen class="h-4 w-4" />{{ t('onboarding.done.books') }}</button>
          </div>
        </div>

        <div class="flex items-center justify-between gap-2 border-t border-stone-100 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-stone-800">
          <button v-if="index > 0" class="btn-ghost" @click="index--"><ChevronLeft class="h-4 w-4" />{{ t('common.back') }}</button>
          <button v-else class="btn-ghost text-stone-500" @click="finish()">{{ t('onboarding.skip') }}</button>
          <button class="btn-primary" :disabled="busy" @click="next">
            {{ isLast ? t('onboarding.finish') : index === 0 ? t('onboarding.start') : t('onboarding.next') }}
            <ChevronRight v-if="!isLast" class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
