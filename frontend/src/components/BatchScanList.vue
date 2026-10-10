<script setup>
import { computed } from 'vue'
import { Check, Loader2, AlertTriangle, XCircle, CloudOff, Upload, Trash2 } from '@lucide/vue'
import BookCover from './BookCover.vue'
import { scans, scanCounts, clearFinishedScans } from '../lib/batch.js'
import { store } from '../lib/store.js'
import { t } from '../lib/i18n.js'
import { locationText } from '../lib/format.js'

/** Bulk scan session list: newest first, one line per scan with its state. */
const items = computed(() => scans.items)

function view(scan) {
  const book = scan.book
  const where = locationText(book?.location) || scan.location_label
  if (scan.state === 'local') {
    return store.online ? { icon: Upload, tone: 'muted', spin: false, text: t('batch.sending') } : { icon: CloudOff, tone: 'muted', text: t('batch.offlineSaved') }
  }
  switch (scan.result) {
    case 'invalid':
      return { icon: XCircle, tone: 'error', text: t('batch.invalid') }
    case 'invalid_location':
      return { icon: XCircle, tone: 'error', text: t('batch.invalidLocation') }
    case 'duplicate':
      return { icon: AlertTriangle, tone: 'warn', text: t('batch.duplicate') }
    case 'existing_here':
      return { icon: AlertTriangle, tone: 'warn', text: t('batch.existingHere') }
    case 'moved':
      return { icon: AlertTriangle, tone: 'warn', text: t('batch.moved', { from: locationText(scan.previous_location) || t('book.noLocation'), to: where }) }
    case 'existing':
      return { icon: AlertTriangle, tone: 'warn', text: t('batch.existing', { where: locationText(scan.previous_location) || t('book.noLocation') }) }
  }
  if (book?.metadata_status === 'pending') return { icon: Loader2, tone: 'muted', spin: true, text: t('batch.loadingMetadata') }
  if (book?.metadata_status === 'failed') return { icon: AlertTriangle, tone: 'error', text: t('batch.notFound'), edit: true }
  return { icon: Check, tone: 'ok', text: t('batch.added', { where }) }
}

const toneClass = {
  ok: 'text-emerald-600 dark:text-emerald-400',
  warn: 'text-amber-600 dark:text-amber-400',
  error: 'text-red-600 dark:text-red-400',
  muted: 'text-stone-400',
}
</script>

<template>
  <section class="card space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">{{ t('batch.listTitle') }}</h2>
      <button v-if="scanCounts.done" class="btn-ghost !py-1 text-sm" @click="clearFinishedScans"><Trash2 class="h-4 w-4" />{{ t('batch.clearDone') }}</button>
    </div>
    <p class="text-sm text-stone-600 dark:text-stone-400" aria-live="polite">{{ t('batch.counter', { total: scanCounts.total, done: scanCounts.done, waiting: scanCounts.waiting }) }}</p>
    <p v-if="!items.length" class="text-sm text-stone-500">{{ t('batch.empty') }}</p>
    <TransitionGroup tag="ul" name="scan" class="divide-y divide-stone-100 dark:divide-stone-800">
      <li v-for="scan in items" :key="scan.id" class="flex items-center gap-3 py-2">
        <BookCover v-if="scan.book" :book="scan.book" size="sm" />
        <div v-else class="flex h-14 w-10 shrink-0 items-center justify-center rounded-md bg-stone-100 text-stone-400 dark:bg-stone-800"><Upload class="h-4 w-4" /></div>
        <div class="min-w-0 flex-1">
          <component :is="scan.book ? 'RouterLink' : 'div'" :to="scan.book ? `/books/${scan.book.id}` : undefined" class="block truncate font-medium">
            {{ scan.book && scan.book.metadata_status !== 'pending' ? scan.book.title : scan.isbn }}
          </component>
          <div class="truncate text-xs text-stone-500">{{ (scan.book?.metadata_status !== 'pending' && scan.book?.authors) || locationText(scan.book?.location) || scan.location_label }}</div>
          <div class="mt-0.5 flex items-center gap-1 text-xs" :class="toneClass[view(scan).tone]">
            <component :is="view(scan).icon" class="h-3.5 w-3.5 shrink-0" :class="{ 'animate-spin': view(scan).spin }" />
            <span class="truncate">{{ view(scan).text }}</span>
            <RouterLink v-if="view(scan).edit" :to="`/books/${scan.book.id}/edit`" class="ml-1 shrink-0 font-medium underline">{{ t('batch.fillIn') }}</RouterLink>
          </div>
        </div>
      </li>
    </TransitionGroup>
  </section>
</template>

<style scoped>
.scan-enter-active {
  transition: all 0.25s ease-out;
}
.scan-enter-from {
  opacity: 0;
  transform: translateY(-0.5rem);
}
</style>
