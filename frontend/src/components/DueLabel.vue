<script setup>
import { computed } from 'vue'
import { t } from '../lib/i18n.js'
import { formatDate } from '../lib/format.js'

const props = defineProps({ book: { type: Object, required: true } })

/** Days until due computed on the client so cached data stays correct offline. */
const days = computed(() => {
  if (!props.book.loan_due) return null
  const due = new Date(`${props.book.loan_due}T00:00:00`)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((due - today) / 86400000)
})

const label = computed(() => {
  if (props.book.status === 'returned') return t('due.returned')
  if (days.value === null) return t('due.noDate')
  if (days.value < 0) return t('due.overdue', { n: -days.value })
  if (days.value === 0) return t('due.today')
  if (days.value === 1) return t('due.tomorrow')
  return t('due.inDays', { n: days.value })
})

const tone = computed(() => {
  if (props.book.status === 'returned' || days.value === null) return ''
  if (days.value < 0) return 'text-red-700 dark:text-red-400 font-semibold'
  if (days.value <= 3) return 'text-amber-700 dark:text-amber-400 font-semibold'
  return ''
})
defineExpose({ days })
</script>

<template>
  <span :class="tone" :title="book.loan_due ? formatDate(book.loan_due) : ''">{{ label }}</span>
</template>
