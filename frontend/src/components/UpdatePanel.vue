<script setup>
import { ref, onMounted } from 'vue'
import { RefreshCw, ExternalLink, CheckCircle2, ArrowUpCircle } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { formatDate } from '../lib/format.js'
import { store, toast } from '../lib/store.js'

/** Admin-only check for newer BookSpot releases on GitHub (server-side, cached 24 h). */
const info = ref(null)
const checking = ref(false)
const loadError = ref('')

function syncStore(update) {
  store.appUpdate = update?.update_available && update.latest_version
    ? { latest_version: update.latest_version, url: update.url, published_at: update.published_at }
    : null
}

async function check(force = false) {
  if (checking.value || !store.online) return
  checking.value = true
  try {
    const res = await api('v1/admin/update-check', { method: 'POST', body: { force } })
    info.value = res.update
    syncStore(res.update)
    if (force) {
      if (res.update?.error) {
        toast(t(`updates.error_${res.update.error}`), 'error')
      } else if (res.update?.update_available) {
        toast(t('updates.availableToast', { v: res.update.latest_version }), 'info', 5000)
      } else if (res.update?.enabled) {
        toast(t('updates.upToDate', { v: res.update.current_version }), 'success')
      }
    }
  } catch (e) {
    if (force) toast(errorMessage(e), 'error')
  } finally {
    checking.value = false
  }
}

onMounted(async () => {
  try {
    const sys = await api('v1/admin/system')
    info.value = sys.update ?? null
    syncStore(info.value)
    if (info.value?.enabled && info.value.stale && store.online) {
      check(false)
    }
  } catch (e) {
    loadError.value = errorMessage(e)
  }
})
</script>

<template>
  <div class="space-y-2 border-t border-stone-200 pt-3 text-sm dark:border-stone-800">
    <p v-if="loadError" class="text-xs text-red-700 dark:text-red-400">{{ loadError }}</p>
    <template v-else-if="info">
      <p v-if="!info.enabled" class="text-xs text-stone-500">{{ t('updates.disabled') }}</p>
      <template v-else>
        <div
          v-if="info.update_available"
          class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 p-3 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
        >
          <div class="flex items-start gap-2">
            <ArrowUpCircle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <div>
              <div class="font-medium">{{ t('updates.available', { v: info.latest_version }) }}</div>
              <div v-if="info.published_at" class="text-xs opacity-80">{{ t('updates.published', { date: formatDate(info.published_at) }) }}</div>
            </div>
          </div>
          <a
            v-if="info.url"
            :href="info.url"
            target="_blank"
            rel="noopener"
            class="btn-primary !py-1.5 text-xs"
          >
            {{ t('updates.openRelease') }}
            <ExternalLink class="h-3.5 w-3.5" />
          </a>
        </div>
        <p v-else-if="info.latest_version" class="flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400">
          <CheckCircle2 class="h-4 w-4 shrink-0" />
          {{ t('updates.upToDate', { v: info.current_version }) }}
        </p>
        <p v-else-if="info.checked_at && !info.error" class="text-xs text-stone-500">
          {{ t('updates.noRelease') }}
        </p>
        <p v-if="info.error" class="text-xs text-amber-700 dark:text-amber-400">
          {{ t(`updates.error_${info.error}`) }}
        </p>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-xs text-stone-500">
            {{ info.checked_at ? t('updates.lastChecked', { date: formatDate(info.checked_at.slice(0, 10)) }) : t('updates.neverChecked') }}
          </span>
          <button
            type="button"
            class="btn-ghost !px-2 !py-1 text-xs"
            :disabled="checking || !store.online"
            @click="check(true)"
          >
            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': checking }" />
            {{ checking ? t('updates.checking') : t('updates.checkNow') }}
          </button>
        </div>
      </template>
    </template>
  </div>
</template>
