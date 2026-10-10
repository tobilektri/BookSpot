<script setup>
import { ref, computed, onMounted } from 'vue'
import { Copy, RefreshCw } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'

/** Shows the web cron URL (token from .env or generated automatically). */
const info = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    info.value = await api('v1/admin/system')
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const cronUrl = computed(() => (info.value ? new URL(`api/v1/cron?token=${info.value.cron_token}`, document.baseURI).href : ''))

async function copy() {
  try {
    await navigator.clipboard.writeText(cronUrl.value)
    toast(t('cron.copied'), 'success')
  } catch {
    toast(cronUrl.value, 'info', 10000)
  }
}

async function regenerate() {
  if (!confirm(t('cron.regenerateConfirm'))) return
  try {
    info.value = { ...info.value, ...(await api('v1/admin/cron-token', { method: 'POST' })) }
    toast(t('cron.regenerated'), 'success')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="space-y-3 text-sm">
    <p class="text-stone-600 dark:text-stone-400">{{ t('cron.hint') }}</p>
    <p v-if="error" class="text-red-700">{{ error }}</p>
    <template v-if="info">
      <div class="flex gap-2">
        <input :value="cronUrl" class="input font-mono !text-xs" readonly :aria-label="t('cron.url')" @focus="$event.target.select()" />
        <button class="btn-secondary shrink-0" :title="t('cron.copy')" @click="copy"><Copy class="h-4 w-4" /></button>
      </div>
      <p class="text-xs text-stone-500">{{ info.cron_token_from_config ? t('cron.fromConfig') : t('cron.generated') }}</p>
      <p class="text-xs text-stone-500">{{ t('cron.docker') }} <code>php backend/bin/cron.php</code></p>
      <button v-if="!info.cron_token_from_config" class="btn-ghost !px-2 !py-1 text-xs" @click="regenerate"><RefreshCw class="h-3.5 w-3.5" />{{ t('cron.regenerate') }}</button>
    </template>
  </div>
</template>
