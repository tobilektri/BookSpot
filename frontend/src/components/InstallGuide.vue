<script setup>
import { computed } from 'vue'
import { Share, SquarePlus, Ellipsis, EllipsisVertical, Compass, ToggleRight, Smartphone, Copy } from '@lucide/vue'
import { t } from '../lib/i18n.js'
import { platform, installMethod } from '../lib/engagement.js'
import { toast } from '../lib/store.js'

/** Platform specific installation instructions (iOS in detail, Android menu, macOS Safari). */
const props = defineProps({ method: { type: String, default: null } })
const current = computed(() => props.method ?? installMethod())
const oldIos = computed(() => platform.iosVersion !== null && platform.iosVersion < 16.4)

async function copyLink() {
  try {
    await navigator.clipboard.writeText(window.location.origin + window.location.pathname)
    toast(t('install.linkCopied'), 'success')
  } catch {
    toast(window.location.href, 'info', 10000)
  }
}
</script>

<template>
  <div class="space-y-3 text-sm">
    <!-- In-app browser on iOS: has to be opened in Safari first -->
    <template v-if="current === 'ios-inapp'">
      <p>{{ t('install.inAppIntro') }}</p>
      <ol class="space-y-2">
        <li class="flex gap-3"><span class="step">1</span><span>{{ t('install.inAppStep1') }} <Ellipsis class="inline h-4 w-4" /> / <Share class="inline h-4 w-4" /></span></li>
        <li class="flex gap-3"><span class="step">2</span><span>{{ t('install.inAppStep2') }} <Compass class="inline h-4 w-4" /></span></li>
        <li class="flex gap-3"><span class="step">3</span><span>{{ t('install.inAppStep3') }}</span></li>
      </ol>
      <button class="btn-secondary !py-1.5" @click="copyLink"><Copy class="h-4 w-4" />{{ t('install.copyLink') }}</button>
    </template>

    <!-- iPhone / iPad -->
    <template v-else-if="current === 'ios'">
      <p v-if="!platform.iosSafari" class="rounded-lg bg-amber-50 p-2 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">{{ t('install.iosOtherBrowser') }}</p>
      <ol class="space-y-3">
        <li class="flex gap-3">
          <span class="step">1</span>
          <span>
            {{ t('install.iosStep1') }}
            <span class="icon-chip"><Share class="h-4 w-4" /></span>
            <span class="mt-1 block text-xs text-stone-500">{{ t('install.iosStep1Newer') }} <span class="icon-chip"><Ellipsis class="h-3.5 w-3.5" /></span> → „{{ t('install.share') }}“. {{ t('install.iosStep1Ipad') }}</span>
          </span>
        </li>
        <li class="flex gap-3">
          <span class="step">2</span>
          <span>
            {{ t('install.iosStep2') }} <span class="icon-chip"><SquarePlus class="h-4 w-4" /> {{ t('install.addToHome') }}</span>
            <span class="mt-1 block text-xs text-stone-500">{{ t('install.iosStep2Hint') }}</span>
          </span>
        </li>
        <li class="flex gap-3">
          <span class="step">3</span>
          <span>{{ t('install.iosStep3') }} <span class="icon-chip"><ToggleRight class="h-4 w-4" /> {{ t('install.openAsWebApp') }}</span> {{ t('install.iosStep3b') }} „{{ t('install.add') }}“.</span>
        </li>
        <li class="flex gap-3">
          <span class="step">4</span>
          <span>{{ t('install.iosStep4') }} <span class="icon-chip"><Smartphone class="h-4 w-4" /></span></span>
        </li>
      </ol>
      <p class="rounded-lg bg-brand-50 p-2 text-brand-900 dark:bg-brand-900/30 dark:text-brand-200">{{ oldIos ? t('install.iosPushOld') : t('install.iosPush') }}</p>
    </template>

    <!-- Android without native prompt (e.g. Firefox) -->
    <template v-else-if="current === 'android-menu'">
      <ol class="space-y-2">
        <li class="flex gap-3"><span class="step">1</span><span>{{ t('install.androidStep1') }} <span class="icon-chip"><EllipsisVertical class="h-4 w-4" /></span></span></li>
        <li class="flex gap-3"><span class="step">2</span><span>{{ t('install.androidStep2') }}</span></li>
        <li class="flex gap-3"><span class="step">3</span><span>{{ t('install.androidStep3') }}</span></li>
      </ol>
    </template>

    <template v-else-if="current === 'mac-safari'">
      <p>{{ t('install.macSafari') }}</p>
    </template>
  </div>
</template>

<style scoped>
@reference '../style.css';
.step {
  @apply flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white;
}
.icon-chip {
  @apply inline-flex items-center gap-1 rounded-md bg-stone-100 px-1.5 py-0.5 align-middle text-xs font-medium dark:bg-stone-800;
}
</style>
