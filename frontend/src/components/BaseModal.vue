<script>
const openModals = []
let nextId = 0
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
</script>

<script setup>
import { ref, nextTick, onMounted, onUnmounted } from 'vue'
import { X } from '@lucide/vue'
import { t } from '../lib/i18n.js'

defineProps({ title: String, wide: Boolean })
const emit = defineEmits(['close'])
const dialog = ref(null)
const titleId = `modal-title-${++nextId}`
// Stacked dialogs (e.g. "Move to …" -> "New location"): Escape and Tab only act on the top one.
const token = Symbol('modal')
let previousFocus = null

function onKey(e) {
  if (openModals.at(-1) !== token) return
  if (e.key === 'Escape') emit('close')
  if (e.key !== 'Tab' || !dialog.value) return
  // Keep keyboard focus inside the dialog.
  const items = [...dialog.value.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null)
  if (!items.length) return
  const first = items[0]
  const last = items.at(-1)
  if (e.shiftKey && (document.activeElement === first || !dialog.value.contains(document.activeElement))) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

onMounted(async () => {
  previousFocus = document.activeElement
  openModals.push(token)
  document.addEventListener('keydown', onKey)
  await nextTick()
  // A field marked autofocus, otherwise the dialog itself (no keyboard popping up on phones).
  ;(dialog.value?.querySelector('[autofocus]') ?? dialog.value)?.focus()
})
onUnmounted(() => {
  openModals.splice(openModals.indexOf(token), 1)
  document.removeEventListener('keydown', onKey)
  previousFocus?.focus?.() // back to the button that opened it
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4" @click.self="emit('close')">
      <div
        ref="dialog"
        class="flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl outline-none sm:rounded-2xl dark:bg-stone-900"
        :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
      >
        <div class="flex items-center justify-between border-b border-stone-200 px-5 py-3.5 dark:border-stone-800">
          <h2 :id="titleId" class="font-semibold">{{ title }}</h2>
          <button class="btn-ghost !p-1.5" :aria-label="t('common.close')" @click="emit('close')"><X class="h-5 w-5" /></button>
        </div>
        <!-- Bottom padding never below 1.25rem, more on iPhones with home indicator. -->
        <div class="overflow-y-auto px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
