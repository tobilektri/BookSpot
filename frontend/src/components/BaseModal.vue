<script setup>
import { onMounted, onUnmounted } from 'vue'
import { X } from '@lucide/vue'

defineProps({ title: String, wide: Boolean })
const emit = defineEmits(['close'])
const onKey = (e) => e.key === 'Escape' && emit('close')
onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4" @click.self="emit('close')">
      <div
        class="flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl dark:bg-stone-900"
        :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'"
        role="dialog"
        aria-modal="true"
      >
        <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3 dark:border-stone-800">
          <h2 class="font-semibold">{{ title }}</h2>
          <button class="btn-ghost !p-1.5" aria-label="Close" @click="emit('close')"><X class="h-5 w-5" /></button>
        </div>
        <div class="safe-bottom overflow-y-auto p-4">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
