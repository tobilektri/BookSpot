<script setup>
import { ref, onMounted } from 'vue'
import { Bell, BellOff } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { pushSupported, currentSubscription, enablePush, disablePush } from '../lib/push.js'

const supported = pushSupported()
const serverInfo = ref(null)
const subscribed = ref(false)
const busy = ref(false)
const denied = ref(supported && Notification.permission === 'denied')

onMounted(async () => {
  try {
    serverInfo.value = await api('push.php')
    subscribed.value = Boolean(await currentSubscription())
  } catch {
    serverInfo.value = { available: false }
  }
})

async function enable() {
  busy.value = true
  try {
    await enablePush(serverInfo.value.public_key)
    subscribed.value = true
    toast(t('settings.pushEnabled'), 'success')
  } catch (e) {
    if (e.code === 'denied') denied.value = true
    else toast(errorMessage(e), 'error')
  } finally {
    busy.value = false
  }
}

async function disable() {
  busy.value = true
  await disablePush().catch(() => {})
  subscribed.value = false
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
    <p v-if="!supported" class="text-amber-700">{{ t('settings.pushUnsupported') }}</p>
    <p v-else-if="serverInfo && (!serverInfo.available || !serverInfo.public_key)" class="text-amber-700">{{ t('settings.pushServerMissing') }}</p>
    <p v-else-if="denied" class="text-amber-700">{{ t('settings.pushDenied') }}</p>
    <div v-else-if="serverInfo" class="flex flex-wrap gap-2">
      <template v-if="subscribed">
        <span class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><Bell class="h-4 w-4" />{{ t('settings.pushEnabled') }}</span>
        <button class="btn-secondary !py-1.5" @click="test">{{ t('settings.pushTest') }}</button>
        <button class="btn-ghost !py-1.5" :disabled="busy" @click="disable"><BellOff class="h-4 w-4" />{{ t('settings.pushDisable') }}</button>
      </template>
      <button v-else class="btn-primary" :disabled="busy" @click="enable"><Bell class="h-4 w-4" />{{ t('settings.pushEnable') }}</button>
    </div>
  </div>
</template>
