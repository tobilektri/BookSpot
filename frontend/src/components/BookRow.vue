<script setup>
import { MapPin, CalendarClock, Users } from '@lucide/vue'
import BookCover from './BookCover.vue'
import StatusBadge from './StatusBadge.vue'
import { t } from '../lib/i18n.js'
import { locationText } from '../lib/format.js'
import DueLabel from './DueLabel.vue'

defineProps({ book: { type: Object, required: true }, showLocation: { type: Boolean, default: true } })
</script>

<template>
  <RouterLink :to="`/books/${book.id}`" class="flex gap-3 rounded-xl p-2 transition hover:bg-stone-100 dark:hover:bg-stone-900">
    <BookCover :book="book" size="sm" />
    <div class="min-w-0 flex-1">
      <div class="truncate font-medium">{{ book.title }}</div>
      <div class="truncate text-sm text-stone-500 dark:text-stone-400">{{ book.authors || t('common.unknownAuthor') }}</div>
      <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-stone-500 dark:text-stone-400">
        <StatusBadge :status="book.personal?.reading_status" />
        <span v-if="book.entry_type !== 'owned'" class="inline-flex items-center gap-1"><CalendarClock class="h-3.5 w-3.5" /><DueLabel :book="book" /></span>
        <span v-else-if="showLocation && book.location" class="inline-flex items-center gap-1"><MapPin class="h-3.5 w-3.5" />{{ locationText(book.location) }}</span>
        <span v-if="book.lent_to" class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400"><Users class="h-3.5 w-3.5" />{{ book.lent_to }}</span>
      </div>
    </div>
  </RouterLink>
</template>
