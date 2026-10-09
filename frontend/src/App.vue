<script setup>
import { computed, watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { House, BookOpen, ScanBarcode, LibraryBig, ChartColumn, Settings, WifiOff, RefreshCw, VenetianMask } from '@lucide/vue'
import { api } from './lib/api.js'
import { switchIdentity } from './lib/session.js'
import { errorMessage } from './lib/i18n.js'
import { toast } from './lib/store.js'
import { store } from './lib/store.js'
import { t, appName } from './lib/i18n.js'
import logoUrl from './assets/logo.svg'
import ToastStack from './components/ToastStack.vue'
import EngagementPrompts from './components/EngagementPrompts.vue'

const route = useRoute()
const router = useRouter()

// Session ended while the app was open (401 from the API): go to the login page right away.
watch(
  () => store.user,
  (user) => {
    if (!user && store.ready && !route.meta.public) router.replace({ name: 'login', query: { next: route.fullPath } })
  },
)
const showChrome = computed(() => store.user && route.name !== 'login' && !route.meta.bare)

const nav = computed(() => [
  { to: '/', name: 'home', label: t('nav.home'), icon: House },
  { to: '/books', name: 'books', label: t('nav.books'), icon: BookOpen },
  { to: '/scan', name: 'scan', label: t('nav.scan'), icon: ScanBarcode, primary: true },
  { to: '/shelves', name: 'shelves', label: t('nav.shelves'), icon: LibraryBig },
  { to: '/stats', name: 'stats', label: t('nav.stats'), icon: ChartColumn },
])

function isActive(item) {
  if (item.to === '/') return route.path === '/'
  return route.path.startsWith(item.to)
}

const leaving = ref(false)
async function stopImpersonation() {
  leaving.value = true
  try {
    await switchIdentity(() => api('auth.php', { method: 'POST', query: { action: 'stop_impersonation' } }), '#/settings#users')
  } catch (e) {
    leaving.value = false
    toast(errorMessage(e), 'error')
  }
}

function applyUpdate() {
  store.updateAvailable?.waiting?.postMessage({ type: 'SKIP_WAITING' })
}
</script>

<template>
  <div class="min-h-dvh">
    <header
      v-if="showChrome"
      class="sticky top-0 z-30 border-b border-stone-200 bg-stone-50/90 backdrop-blur dark:border-stone-800 dark:bg-stone-950/90"
    >
      <!-- Impersonation bar: always visible while an admin acts as another user -->
      <div
        v-if="store.impersonator && store.user"
        class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-violet-700 px-4 py-2 text-center text-sm text-white"
        role="status"
      >
        <VenetianMask class="h-4 w-4 shrink-0" />
        <span>{{ t('impersonate.banner', { user: store.user.username, admin: store.impersonator.username }) }}</span>
        <button class="rounded-lg bg-white/20 px-3 py-1 font-medium hover:bg-white/30" :disabled="leaving" @click="stopImpersonation">
          {{ t('impersonate.stop', { admin: store.impersonator.username }) }}
        </button>
      </div>
      <div class="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4">
        <RouterLink to="/" class="flex items-center gap-2 font-serif text-lg font-semibold text-brand-700 dark:text-brand-400">
          <img :src="logoUrl" alt="" class="h-7 w-7" />
          <span>{{ store.appName || appName }}</span>
        </RouterLink>
        <nav class="ml-6 hidden items-center gap-1 md:flex" aria-label="Main">
          <RouterLink
            v-for="item in nav"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium"
            :class="isActive(item) ? 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-300' : 'text-stone-600 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800'"
          >
            <component :is="item.icon" class="h-4 w-4" />{{ item.label }}
          </RouterLink>
        </nav>
        <div class="ml-auto flex items-center gap-2">
          <span v-if="store.syncing" class="flex items-center gap-1 text-xs text-stone-500"><RefreshCw class="h-3.5 w-3.5 animate-spin" />{{ t('common.syncing') }}</span>
          <RouterLink to="/settings" class="btn-ghost !p-2" :aria-label="t('nav.settings')" :title="t('nav.settings')">
            <Settings class="h-5 w-5" />
          </RouterLink>
        </div>
      </div>
      <div v-if="!store.online || store.pendingOps" class="flex items-center justify-center gap-2 bg-amber-100 px-4 py-1.5 text-center text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-200" role="status">
        <WifiOff v-if="!store.online" class="h-3.5 w-3.5" />
        <span v-if="!store.online">{{ t('common.offline') }}</span>
        <span v-if="store.pendingOps">{{ t('common.pending', { n: store.pendingOps }) }}</span>
      </div>
    </header>

    <main :class="showChrome ? 'mx-auto max-w-6xl px-4 pt-4 pb-28 md:pb-10' : ''">
      <RouterView v-slot="{ Component }">
        <component :is="Component" />
      </RouterView>
    </main>

    <nav
      v-if="showChrome"
      class="safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white/95 backdrop-blur md:hidden dark:border-stone-800 dark:bg-stone-900/95"
      aria-label="Main"
    >
      <div class="mx-auto grid max-w-md grid-cols-5">
        <RouterLink
          v-for="item in nav"
          :key="item.to"
          :to="item.to"
          class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium"
          :class="isActive(item) ? 'text-brand-700 dark:text-brand-400' : 'text-stone-500 dark:text-stone-400'"
        >
          <span v-if="item.primary" class="-mt-6 flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg ring-4 ring-stone-50 dark:ring-stone-950">
            <component :is="item.icon" class="h-6 w-6" />
          </span>
          <component :is="item.icon" v-else class="h-5 w-5" />
          {{ item.label }}
        </RouterLink>
      </div>
    </nav>

    <div v-if="store.updateAvailable" class="fixed inset-x-4 bottom-24 z-40 mx-auto flex max-w-md items-center justify-between gap-3 rounded-xl bg-stone-900 px-4 py-3 text-sm text-white shadow-xl md:bottom-6 dark:bg-stone-100 dark:text-stone-900">
      {{ t('common.update') }}
      <button class="btn-primary !py-1.5" @click="applyUpdate">{{ t('common.reload') }}</button>
    </div>

    <EngagementPrompts v-if="showChrome && !store.updateAvailable && !store.impersonator" />
    <ToastStack />
  </div>
</template>
