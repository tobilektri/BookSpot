<script setup>
import { ref, computed } from 'vue'
import { bookColor } from '../lib/format.js'
import { t } from '../lib/i18n.js'

/**
 * Asymmetric shelf visualisation: columns side by side, each with its own
 * number of compartments. In "view" mode book spines are drawn and can be
 * dragged between compartments; in "pick" mode a compartment is selected.
 */
const props = defineProps({
  shelf: { type: Object, required: true },
  books: { type: Array, default: () => [] },
  mode: { type: String, default: 'view' }, // view | pick | preview
  selected: { type: Object, default: null }, // { col, row }
  highlight: { type: Object, default: null },
})
const emit = defineEmits(['select', 'drop'])

const dragOver = ref(null)

const byCompartment = computed(() => {
  const map = {}
  for (const book of props.books) {
    if (book.shelf_col == null || book.shelf_row == null) continue
    ;(map[`${book.shelf_col}:${book.shelf_row}`] ??= []).push(book)
  }
  return map
})

function count(colIndex, rowIndex) {
  const key = `${colIndex + 1}:${rowIndex + 1}`
  if (props.books.length) return byCompartment.value[key]?.length ?? 0
  return props.shelf.columns[colIndex]?.counts?.[rowIndex] ?? 0
}

function isSelected(c, r) {
  const s = props.selected ?? props.highlight
  return s && s.col === c + 1 && s.row === r + 1
}

function onDragStart(event, book) {
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('application/x-bookspot-books', JSON.stringify([book.id]))
  event.dataTransfer.setData('text/plain', book.title)
}

function onDrop(event, c, r) {
  dragOver.value = null
  const raw = event.dataTransfer.getData('application/x-bookspot-books')
  if (!raw) return
  try {
    emit('drop', JSON.parse(raw), c + 1, r + 1)
  } catch {
    /* ignore foreign drops */
  }
}

const compartmentHeight = computed(() => (props.mode === 'view' ? 'min-h-24' : props.mode === 'pick' ? 'min-h-11' : 'min-h-7'))
</script>

<template>
  <div class="no-scrollbar overflow-x-auto">
    <div class="flex min-w-max gap-1.5 rounded-xl bg-amber-900/80 p-1.5 shadow-inner dark:bg-amber-950">
      <div v-for="(column, c) in shelf.columns" :key="c" class="flex flex-col gap-1.5" :class="mode === 'preview' ? 'w-12' : mode === 'pick' ? 'w-20' : 'w-36 sm:w-44'">
        <div v-if="mode !== 'preview'" class="truncate px-1 text-center text-xs font-semibold text-amber-50">{{ column.label }}</div>
        <button
          v-for="(name, r) in column.rows"
          :key="r"
          type="button"
          class="relative flex flex-col rounded-md border-2 bg-amber-50 text-left transition dark:bg-stone-800"
          :class="[
            compartmentHeight,
            isSelected(c, r) ? 'border-brand-500 ring-2 ring-brand-400' : 'border-transparent hover:border-brand-300',
            dragOver === `${c}:${r}` ? 'border-dashed !border-brand-500 bg-brand-100 dark:bg-brand-900/40' : '',
          ]"
          :title="name"
          :aria-label="`${name}: ${count(c, r)}`"
          @click="emit('select', c + 1, r + 1)"
          @dragover.prevent="dragOver = `${c}:${r}`"
          @dragleave="dragOver = null"
          @drop.prevent="onDrop($event, c, r)"
        >
          <span class="flex items-center justify-between px-1.5 pt-0.5 text-[10px] font-medium text-stone-500 dark:text-stone-400">
            <span class="truncate">{{ name }}</span>
            <span v-if="mode !== 'preview' && count(c, r)" class="rounded bg-stone-200 px-1 dark:bg-stone-700">{{ count(c, r) }}</span>
          </span>
          <span v-if="mode === 'view'" class="flex flex-1 items-end gap-px overflow-hidden px-1 pb-1">
            <span
              v-for="book in (byCompartment[`${c + 1}:${r + 1}`] ?? []).slice(0, 24)"
              :key="book.id"
              draggable="true"
              class="flex h-full max-h-20 min-h-14 w-3.5 shrink-0 cursor-grab items-center justify-center overflow-hidden rounded-[2px] text-[7px] leading-none text-white/90 shadow-sm sm:w-4"
              :style="{ background: bookColor(book.title), height: `${60 + ((book.pages || 300) % 7) * 5}%` }"
              :title="`${book.title} – ${book.authors}`"
              @dragstart="onDragStart($event, book)"
            >
              <span class="whitespace-nowrap [writing-mode:vertical-rl] rotate-180">{{ book.title.slice(0, 18) }}</span>
            </span>
          </span>
        </button>
      </div>
    </div>
    <p v-if="mode === 'view' && books.length" class="mt-2 hidden text-xs text-stone-500 md:block">{{ t('shelves.dragHint') }}</p>
  </div>
</template>
