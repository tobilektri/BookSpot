<script setup>
import { t } from '../lib/i18n.js'
defineProps({ status: { type: String, default: 'unread' } })
const classes = {
  unread: 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
  reading: 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300',
  read: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300',
  abandoned: 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300',
}
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="classes[status] ?? classes.unread">{{ t(`status.${status}`) }}</span>
</template>
