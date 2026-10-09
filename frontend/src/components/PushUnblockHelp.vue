<script setup>
import { Lock, Settings2 } from '@lucide/vue'
import { t } from '../lib/i18n.js'
import { platform } from '../lib/engagement.js'
</script>

<template>
  <div class="space-y-2 text-sm">
    <p>{{ t('pushOffer.deniedIntro') }}</p>
    <p v-if="platform.ios" class="flex gap-2"><Settings2 class="h-4 w-4 shrink-0" />{{ t('pushOffer.deniedIos') }}</p>
    <p v-else class="flex gap-2"><Lock class="h-4 w-4 shrink-0" />{{ t('pushOffer.deniedBrowser') }}</p>
  </div>
</template>
