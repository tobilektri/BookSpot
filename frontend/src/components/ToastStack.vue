<script setup>
import { X } from '@lucide/vue'
import { store, dismissToast } from '../lib/store.js'
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 top-16 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite">
    <TransitionGroup name="toast">
      <div
        v-for="toast in store.toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl px-4 py-3 text-sm shadow-lg"
        :class="{
          'bg-emerald-600 text-white': toast.type === 'success',
          'bg-red-600 text-white': toast.type === 'error',
          'bg-stone-800 text-white dark:bg-stone-200 dark:text-stone-900': toast.type === 'info',
        }"
        role="status"
      >
        <span class="flex-1">{{ toast.message }}</span>
        <button class="opacity-80 hover:opacity-100" aria-label="Close" @click="dismissToast(toast.id)"><X class="h-4 w-4" /></button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.2s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
