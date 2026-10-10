<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Plus, Trash2, Wand2, GripVertical, GripHorizontal, ArrowUpDown, X } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { invalidateShelves } from '../lib/shelves.js'
import { invalidateCatalog } from '../lib/catalog.js'
import ShelfGrid from '../components/ShelfGrid.vue'

/**
 * Shelf editor. Compartments can be rearranged by drag & drop (mouse, touch) or
 * with the arrow keys on their handle – within a column and between columns;
 * whole columns can be reordered as well. Every compartment remembers where it
 * came from (`from`), so the server moves its books along.
 */
const props = defineProps({ id: { type: String, default: null } })
const router = useRouter()
const isEdit = computed(() => Boolean(props.id))
const form = reactive({ name: '', room: '', description: '' })
const columns = ref([])
const originalCounts = ref({}) // "col:row" -> number of books before editing
const errors = ref({})
const busy = ref(false)
const scroller = ref(null)
let nextKey = 1

const makeRow = (name, from = null, count = 0) => ({ key: nextKey++, name, from, count })
const makeColumn = (label, rows) => ({ key: nextKey++, label, rows })

onMounted(async () => {
  if (!isEdit.value) {
    columns.value = [
      makeColumn('A', ['A1', 'A2', 'A3', 'A4'].map((n) => makeRow(n))),
      makeColumn('B', ['B1', 'B2'].map((n) => makeRow(n))),
    ]
    return
  }
  try {
    const { shelf } = await api(`v1/shelves/${props.id}`)
    Object.assign(form, { name: shelf.name, room: shelf.room ?? '', description: shelf.description ?? '' })
    columns.value = shelf.columns.map((c, ci) =>
      makeColumn(c.label, c.rows.map((name, ri) => makeRow(name, { col: ci + 1, row: ri + 1 }, c.counts?.[ri] ?? 0))),
    )
    originalCounts.value = Object.fromEntries(shelf.columns.flatMap((c, ci) => c.rows.map((_, ri) => [`${ci + 1}:${ri + 1}`, c.counts?.[ri] ?? 0])))
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
})

// ---------------------------------------------------------------- editing

function nextLabel() {
  const used = new Set(columns.value.map((c) => c.label))
  for (let i = 0; i < 26; i++) {
    const label = String.fromCharCode(65 + i)
    if (!used.has(label)) return label
  }
  return String(columns.value.length + 1)
}

function uniqueName(base) {
  const used = new Set(columns.value.flatMap((c) => c.rows.map((r) => r.name.replace(/\s+/g, '').toLowerCase())))
  let name = base
  let i = 2
  while (used.has(name.toLowerCase())) name = `${base}-${i++}`
  return name
}

function addColumn() {
  const label = nextLabel()
  columns.value.push(makeColumn(label, [1, 2, 3, 4].map((n) => makeRow(uniqueName(`${label}${n}`)))))
}

function removeColumn(ci) {
  const books = columns.value[ci].rows.reduce((s, r) => s + r.count, 0)
  if (books && !confirm(t('shelves.removeColumnConfirm', { n: books }))) return
  columns.value.splice(ci, 1)
}

function addRow(column) {
  column.rows.push(makeRow(uniqueName(`${column.label}${column.rows.length + 1}`)))
}

function removeRow(column, ri) {
  column.rows.splice(ri, 1)
}

/** Typical import correction: compartments are numbered from the bottom. */
function reverse(column) {
  column.rows.reverse()
}

/** Re-labels compartments top-down as <column label><number>, e.g. L1, L2, … */
function autoName(column) {
  column.rows.forEach((row, i) => (row.name = `${column.label}${i + 1}`))
}

function renameColumn(column, label) {
  const old = column.label
  column.label = label
  column.rows.forEach((row, i) => {
    if (row.name === `${old}${i + 1}`) row.name = `${label}${i + 1}`
  })
}

// ---------------------------------------------------------------- drag & drop

const drag = reactive({ kind: null, c: null, r: null, x: 0, y: 0, label: '', target: null })

function startDrag(event, kind, c, r = null) {
  if (event.button !== undefined && event.button !== 0) return
  event.preventDefault()
  Object.assign(drag, {
    kind,
    c,
    r,
    x: event.clientX,
    y: event.clientY,
    label: kind === 'row' ? columns.value[c].rows[r].name : `${t('shelves.column')} ${columns.value[c].label}`,
    target: null,
  })
  window.addEventListener('pointermove', onDragMove)
  window.addEventListener('pointerup', endDrag)
  window.addEventListener('pointercancel', cancelDrag)
}

function onDragMove(event) {
  drag.x = event.clientX
  drag.y = event.clientY
  autoScroll(event.clientX, event.clientY)
  const el = document.elementFromPoint(event.clientX, event.clientY)
  if (drag.kind === 'row') {
    const rowEl = el?.closest('[data-row]')
    const colEl = el?.closest('[data-col]')
    if (rowEl) {
      const rect = rowEl.getBoundingClientRect()
      const c = Number(rowEl.dataset.col)
      const r = Number(rowEl.dataset.row)
      drag.target = { c, index: event.clientY > rect.top + rect.height / 2 ? r + 1 : r }
    } else if (colEl) {
      const c = Number(colEl.dataset.col)
      drag.target = { c, index: columns.value[c].rows.length }
    } else {
      drag.target = null
    }
  } else {
    const colEl = el?.closest('[data-col]')
    if (colEl) {
      const rect = colEl.getBoundingClientRect()
      const c = Number(colEl.dataset.col)
      drag.target = { index: event.clientX > rect.left + rect.width / 2 ? c + 1 : c }
    }
  }
}

/** Scrolls the column strip and the page while dragging near their edges. */
function autoScroll(x, y) {
  const box = scroller.value?.getBoundingClientRect()
  if (box) {
    if (x < box.left + 48) scroller.value.scrollLeft -= 12
    else if (x > box.right - 48) scroller.value.scrollLeft += 12
  }
  if (y < 110) window.scrollBy(0, -14)
  else if (y > window.innerHeight - 90) window.scrollBy(0, 14)
}

function endDrag() {
  if (drag.kind === 'row' && drag.target) moveRow(drag.c, drag.r, drag.target.c, drag.target.index)
  if (drag.kind === 'col' && drag.target) moveColumn(drag.c, drag.target.index)
  cancelDrag()
}

function cancelDrag() {
  drag.kind = null
  drag.target = null
  window.removeEventListener('pointermove', onDragMove)
  window.removeEventListener('pointerup', endDrag)
  window.removeEventListener('pointercancel', cancelDrag)
}
onUnmounted(cancelDrag)

function moveRow(fromC, fromR, toC, toIndex) {
  const [row] = columns.value[fromC].rows.splice(fromR, 1)
  if (fromC === toC && toIndex > fromR) toIndex--
  columns.value[toC].rows.splice(Math.max(0, Math.min(toIndex, columns.value[toC].rows.length)), 0, row)
  return row
}

function moveColumn(from, toIndex) {
  const [column] = columns.value.splice(from, 1)
  if (toIndex > from) toIndex--
  columns.value.splice(toIndex, 0, column)
  return column
}

const isDropTarget = (c, index) => drag.kind === 'row' && drag.target?.c === c && drag.target?.index === index
const isColumnDropTarget = (index) => drag.kind === 'col' && drag.target?.index === index

/** Keyboard alternative: arrow keys on a handle move the compartment / column. */
async function rowKey(event, c, r) {
  const moves = { ArrowUp: [c, r - 1], ArrowDown: [c, r + 2], ArrowLeft: [c - 1, r], ArrowRight: [c + 1, r] }
  const target = moves[event.key]
  if (!target) return
  event.preventDefault()
  const [toC, toIndex] = target
  if (toC < 0 || toC >= columns.value.length || toIndex < 0) return
  if (toC === c && toIndex > columns.value[c].rows.length) return
  const row = moveRow(c, r, toC, toIndex)
  await nextTick()
  document.querySelector(`[data-row-handle="${row.key}"]`)?.focus()
}

async function columnKey(event, c) {
  const to = { ArrowLeft: c - 1, ArrowRight: c + 2 }[event.key]
  if (to === undefined) return
  event.preventDefault()
  if (to < 0 || to > columns.value.length) return
  const column = moveColumn(c, to)
  await nextTick()
  document.querySelector(`[data-col-handle="${column.key}"]`)?.focus()
}

// ---------------------------------------------------------------- validation & save

const duplicate = computed(() => {
  const seen = new Set()
  for (const name of columns.value.flatMap((c) => c.rows.map((r) => r.name))) {
    const key = name.replace(/\s+/g, '').toLowerCase()
    if (seen.has(key)) return name
    seen.add(key)
  }
  return null
})
const emptyColumn = computed(() => columns.value.find((c) => c.rows.length === 0)?.label ?? null)
const emptyName = computed(() => columns.value.some((c) => c.rows.some((r) => !r.name.trim())))

/** Books in compartments that were removed lose their position on save. */
const lostBooks = computed(() => {
  const kept = new Set(columns.value.flatMap((c) => c.rows.filter((r) => r.from).map((r) => `${r.from.col}:${r.from.row}`)))
  return Object.entries(originalCounts.value).reduce((sum, [key, n]) => sum + (kept.has(key) ? 0 : n), 0)
})
const changedPositions = computed(() =>
  columns.value.reduce((sum, col, ci) => sum + col.rows.filter((r, ri) => r.from && r.count && (r.from.col !== ci + 1 || r.from.row !== ri + 1)).length, 0),
)

const preview = computed(() => ({
  columns: columns.value.map((c) => ({ label: c.label, rows: c.rows.map((r) => r.name), counts: c.rows.map((r) => r.count) })),
}))

async function submit() {
  if (lostBooks.value && !confirm(t('shelves.lostBooksConfirm', { n: lostBooks.value }))) return
  busy.value = true
  errors.value = {}
  try {
    const body = {
      ...form,
      columns: columns.value.map((c) => ({
        label: c.label,
        rows: c.rows.map((r) => ({ name: r.name, from_col: r.from?.col ?? null, from_row: r.from?.row ?? null })),
      })),
    }
    const result = isEdit.value ? await api(`v1/shelves/${props.id}`, { method: 'PUT', body }) : await api('v1/shelves', { method: 'POST', body })
    invalidateShelves()
    invalidateCatalog()
    if (result.shelf.unassigned_books) toast(t('shelves.unassigned', { n: result.shelf.unassigned_books }), 'info', 6000)
    else toast(t('common.saved'), 'success')
    router.replace(`/shelves/${result.shelf.id}`)
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.fields
    toast(errorMessage(e), 'error')
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!confirm(t('shelves.deleteConfirm', { name: form.name }))) return
  try {
    await api(`v1/shelves/${props.id}`, { method: 'DELETE' })
    invalidateShelves()
    invalidateCatalog()
    router.replace('/shelves')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-4">
    <button class="btn-ghost -ml-2 !px-2" @click="router.back()"><ArrowLeft class="h-4 w-4" />{{ t('common.back') }}</button>
    <h1 class="font-serif text-2xl font-semibold">{{ isEdit ? t('shelves.edit') : t('shelves.new') }}</h1>

    <form class="space-y-4" @submit.prevent="submit">
      <section class="card grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <label class="label" for="name">{{ t('shelves.name') }} *</label>
          <input id="name" v-model="form.name" class="input" required maxlength="100" />
          <p v-if="errors.name" class="field-error">{{ fieldError(errors.name) }}</p>
        </div>
        <div>
          <label class="label" for="room">{{ t('shelves.room') }}</label>
          <input id="room" v-model="form.room" class="input" maxlength="100" />
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="description">{{ t('shelves.description') }}</label>
          <input id="description" v-model="form.description" class="input" />
        </div>
      </section>

      <section class="card space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">{{ t('shelves.columns', { n: columns.length }) }}</h2>
          <button type="button" class="btn-secondary !py-1.5" :disabled="columns.length >= 40" @click="addColumn"><Plus class="h-4 w-4" />{{ t('shelves.addColumn') }}</button>
        </div>
        <p class="text-xs text-stone-500">{{ t('shelves.arrangeHint') }}</p>

        <div ref="scroller" class="no-scrollbar flex gap-1 overflow-x-auto pb-2" :class="drag.kind ? 'select-none' : ''">
          <template v-for="(column, c) in columns" :key="column.key">
            <div class="w-1 shrink-0 rounded-full" :class="isColumnDropTarget(c) ? 'bg-brand-500' : ''"></div>
            <div
              :data-col="c"
              class="w-48 shrink-0 space-y-2 rounded-xl p-3 transition"
              :class="drag.kind === 'col' && drag.c === c ? 'bg-brand-100 opacity-60 dark:bg-brand-900/40' : 'bg-stone-100 dark:bg-stone-800'"
            >
              <div class="flex items-center gap-1">
                <button
                  type="button"
                  class="cursor-grab touch-none rounded p-1 text-stone-400 hover:bg-stone-200 hover:text-stone-700 active:cursor-grabbing dark:hover:bg-stone-700"
                  :data-col-handle="column.key"
                  :aria-label="t('shelves.moveColumn', { name: column.label })"
                  :title="t('shelves.moveColumn', { name: column.label })"
                  @pointerdown="startDrag($event, 'col', c)"
                  @keydown="columnKey($event, c)"
                >
                  <GripHorizontal class="h-4 w-4" />
                </button>
                <input
                  :value="column.label"
                  class="input !px-2 !py-1.5 font-semibold"
                  maxlength="30"
                  :aria-label="t('shelves.columnLabel')"
                  @input="renameColumn(column, $event.target.value)"
                />
                <button type="button" class="btn-ghost !p-1.5 text-red-600" :disabled="columns.length === 1" :aria-label="t('shelves.removeColumn')" :title="t('shelves.removeColumn')" @click="removeColumn(c)">
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>

              <div class="space-y-0.5">
                <template v-for="(row, r) in column.rows" :key="row.key">
                  <div class="h-1 rounded-full" :class="isDropTarget(c, r) ? 'bg-brand-500' : ''"></div>
                  <div
                    :data-row="r"
                    :data-col="c"
                    class="flex items-center gap-1 rounded-lg"
                    :class="drag.kind === 'row' && drag.c === c && drag.r === r ? 'opacity-40' : ''"
                  >
                    <button
                      type="button"
                      class="cursor-grab touch-none rounded p-1 text-stone-400 hover:bg-stone-200 hover:text-stone-700 active:cursor-grabbing dark:hover:bg-stone-700"
                      :data-row-handle="row.key"
                      :aria-label="t('shelves.moveCompartment', { name: row.name })"
                      :title="t('shelves.moveCompartment', { name: row.name })"
                      @pointerdown="startDrag($event, 'row', c, r)"
                      @keydown="rowKey($event, c, r)"
                    >
                      <GripVertical class="h-4 w-4" />
                    </button>
                    <input v-model="row.name" class="input min-w-0 !px-2 !py-1 !text-sm" maxlength="30" :aria-label="`${column.label} ${r + 1}`" />
                    <span v-if="row.count" class="shrink-0 rounded bg-stone-200 px-1 text-[10px] text-stone-600 dark:bg-stone-700 dark:text-stone-300" :title="t('common.books', { n: row.count })">{{ row.count }}</span>
                    <button type="button" class="shrink-0 rounded p-0.5 text-stone-400 hover:text-red-600" :aria-label="t('shelves.removeCompartment', { name: row.name })" :title="t('shelves.removeCompartment', { name: row.name })" @click="removeRow(column, r)">
                      <X class="h-3.5 w-3.5" />
                    </button>
                  </div>
                </template>
                <div class="h-1 rounded-full" :class="isDropTarget(c, column.rows.length) ? 'bg-brand-500' : ''"></div>
                <p v-if="!column.rows.length" class="rounded-lg border-2 border-dashed border-stone-300 p-3 text-center text-xs text-stone-500 dark:border-stone-600">{{ t('shelves.emptyColumnDrop') }}</p>
              </div>

              <div class="grid grid-cols-1 gap-1 border-t border-stone-200 pt-2 dark:border-stone-700">
                <button type="button" class="btn-ghost w-full !justify-start !px-2 !py-1 text-xs" @click="addRow(column)"><Plus class="h-3.5 w-3.5" />{{ t('shelves.addCompartment') }}</button>
                <button type="button" class="btn-ghost w-full !justify-start !px-2 !py-1 text-xs" :disabled="column.rows.length < 2" @click="reverse(column)"><ArrowUpDown class="h-3.5 w-3.5" />{{ t('shelves.reverse') }}</button>
                <button type="button" class="btn-ghost w-full !justify-start !px-2 !py-1 text-xs" @click="autoName(column)"><Wand2 class="h-3.5 w-3.5" />{{ t('shelves.autoNames') }}</button>
              </div>
            </div>
          </template>
          <div class="w-1 shrink-0 rounded-full" :class="isColumnDropTarget(columns.length) ? 'bg-brand-500' : ''"></div>
        </div>

        <p v-if="duplicate" class="field-error">{{ t('shelves.duplicateName', { name: duplicate }) }}</p>
        <p v-else-if="emptyColumn !== null" class="field-error">{{ t('shelves.emptyColumn', { name: emptyColumn }) }}</p>
        <p v-else-if="emptyName" class="field-error">{{ t('shelves.emptyName') }}</p>
        <p v-else-if="errors.columns" class="field-error">{{ fieldError(errors.columns) }}</p>
      </section>

      <section class="card">
        <h2 class="mb-2 font-semibold">{{ t('shelves.preview') }}</h2>
        <ShelfGrid :shelf="preview" mode="pick" />
        <p v-if="changedPositions" class="mt-2 text-xs text-stone-600 dark:text-stone-400">{{ t('shelves.booksMoveAlong', { n: changedPositions }) }}</p>
        <p v-if="lostBooks" class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-400">{{ t('shelves.lostBooks', { n: lostBooks }) }}</p>
      </section>

      <div class="flex items-center justify-between gap-2">
        <button v-if="isEdit" type="button" class="btn-ghost text-red-600" @click="remove"><Trash2 class="h-4 w-4" />{{ t('common.delete') }}</button>
        <span v-else></span>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" @click="router.back()">{{ t('common.cancel') }}</button>
          <button class="btn-primary" :disabled="busy || Boolean(duplicate) || emptyColumn !== null || emptyName">{{ t('common.save') }}</button>
        </div>
      </div>
    </form>

    <!-- Drag ghost following the pointer -->
    <div
      v-if="drag.kind"
      class="pointer-events-none fixed z-50 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white shadow-xl"
      :style="{ left: `${drag.x + 12}px`, top: `${drag.y + 12}px` }"
    >
      {{ drag.label }}
    </div>
  </div>
</template>
