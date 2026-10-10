<script setup>
import { ref, onMounted } from 'vue'
import { Sparkles } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { loadCatalog } from '../lib/catalog.js'

defineProps({ compact: Boolean })
const remaining = ref(0)
const running = ref(false)
let updated = 0
let partial = 0

onMounted(async () => {
  try {
    remaining.value = (await api('v1/books/enrich-status')).remaining
  } catch {
    remaining.value = 0
  }
})

/** Calls the enrichment endpoint in small batches (shared hosting time limits). */
async function run() {
  running.value = true
  updated = 0
  partial = 0
  try {
    while (running.value && remaining.value > 0) {
      const result = await api('v1/books/enrich', { method: 'POST', body: { limit: 5 } })
      updated += result.updated
      remaining.value = result.remaining
      if (result.unavailable) {
        toast(t('settings.metadataUnavailable'), 'error', 6000)
        break
      }
      partial += result.partial
      if (result.processed === 0) break
    }
    if (!remaining.value || updated) toast(t('settings.metadataDone', { updated }), 'success')
    if (partial) toast(t('settings.metadataPartial', { n: partial }), 'info', 8000)
    loadCatalog({ force: true })
  } catch (e) {
    toast(errorMessage(e), 'error')
  } finally {
    running.value = false
  }
}
</script>

<template>
  <section v-if="remaining > 0 || !compact" class="card flex flex-wrap items-center gap-3 text-sm">
    <Sparkles class="h-5 w-5 shrink-0 text-brand-600" />
    <div class="min-w-0 flex-1">
      <template v-if="compact">{{ running ? t('dashboard.enrichRunning', { n: remaining }) : t('dashboard.enrich', { n: remaining }) }}</template>
      <template v-else>
        <div class="font-medium">{{ t('settings.metadataTitle') }}</div>
        <div class="text-stone-500">{{ t('settings.metadataHint') }}</div>
        <div v-if="remaining" class="mt-1 text-xs text-stone-500">{{ running ? t('dashboard.enrichRunning', { n: remaining }) : t('settings.metadataRemaining', { n: remaining }) }}</div>
      </template>
    </div>
    <button v-if="!running" class="btn-secondary !py-1.5" :disabled="!remaining" @click="run">{{ t('dashboard.enrichAction') }}</button>
    <button v-else class="btn-ghost !py-1.5" @click="running = false">{{ t('common.cancel') }}</button>
  </section>
</template>
