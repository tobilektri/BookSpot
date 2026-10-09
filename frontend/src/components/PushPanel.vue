<script setup>
import { ref, onMounted } from 'vue'
import { Bell, BellOff } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { pushState, refreshPushState, pushNeedsInstall, enablePush, disablePush } from '../lib/push.js'
import { resetNudge } from '../lib/engagement.js'
import PushUnblockHelp from './PushUnblockHelp.vue'

const busy = ref(false)
onMounted(refreshPushState)

async function enable() {
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

async function disable() {
  busy.value = true
  await disablePush().catch(() => {})
  busy.value = false
}

async function test() {
  try {
    const result = await api('push.php', { method: 'POST', query: { action: 'test' } })
    toast(t('settings.pushSent', { n: result.sent }), result.sent ? 'success' : 'error')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="space-y-3 text-sm">
    <p class="text-stone-600 dark:text-stone-400">{{ t('settings.pushHint') }}</p>
    <p v-if="pushNeedsInstall()" class="text-amber-700 dark:text-amber-400">
      {{ t('pushOffer.iosInstallFirst') }} <a href="#/settings#install" class="font-medium underline">{{ t('settings.installApp') }}</a>
    </p>
    <p v-else-if="!pushState.supported" class="text-amber-700">{{ t('settings.pushUnsupported') }}</p>
    <p v-else-if="pushState.loaded && !pushState.serverAvailable" class="text-amber-700">{{ t('settings.pushServerMissing') }}</p>
    <template v-else-if="pushState.permission === 'denied'">
      <PushUnblockHelp />
      <button class="btn-secondary !py-1.5" @click="enable">{{ t('pushOffer.retry') }}</button>
    </template>
    <div v-else-if="pushState.loaded" class="flex flex-wrap items-center gap-2">
      <template v-if="pushState.subscribed">
        <span class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><Bell class="h-4 w-4" />{{ t('settings.pushEnabled') }}</span>
        <button class="btn-secondary !py-1.5" @click="test">{{ t('settings.pushTest') }}</button>
        <button class="btn-ghost !py-1.5" :disabled="busy" @click="disable"><BellOff class="h-4 w-4" />{{ t('settings.pushDisable') }}</button>
      </template>
      <button v-else class="btn-primary" :disabled="busy" @click="enable"><Bell class="h-4 w-4" />{{ t('settings.pushEnable') }}</button>
    </div>
  </div>
</template>
