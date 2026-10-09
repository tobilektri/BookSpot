<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { LibraryBig, Rows3 } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { shelves, loadShelves } from '../lib/shelves.js'
import BaseModal from './BaseModal.vue'

/**
 * Creates a location on the fly (e.g. while adding a book): either one more
 * compartment in an existing shelf, or a whole new shelf. Emits `created` with
 * the new position { shelf_id, shelf_col, shelf_row }.
 */
const props = defineProps({ shelfId: { type: Number, default: null } })
const emit = defineEmits(['close', 'created'])

const NEW_COLUMN = -1
const mode = ref(shelves.value.length ? 'compartment' : 'shelf')
const busy = ref(false)
const error = ref('')

// ---- new compartment in an existing shelf
const comp = reactive({ shelfId: props.shelfId ?? shelves.value[0]?.id ?? null, column: 0, newColumnLabel: '', name: '' })
const targetShelf = computed(() => shelves.value.find((s) => s.id === comp.shelfId) ?? null)

function suggestName() {
  const shelf = targetShelf.value
  if (!shelf) return ''
  if (comp.column === NEW_COLUMN) return comp.newColumnLabel ? `${comp.newColumnLabel}1` : ''
  const column = shelf.columns[comp.column]
  if (!column) return ''
  const used = new Set(shelf.columns.flatMap((c) => c.rows.map((r) => r.toLowerCase())))
  // Continue the numbering of the column (L1 … L9 -> L10), otherwise label + count.
  let n = column.rows.length + 1
  for (const name of column.rows) {
    const m = name.match(/^(.*?)(\d+)$/)
    if (m && m[1] === column.label) n = Math.max(n, Number(m[2]) + 1)
  }
  let name = `${column.label}${n}`
  while (used.has(name.toLowerCase())) name = `${column.label}${++n}`
  return name
}

function nextColumnLabel() {
  const used = new Set((targetShelf.value?.columns ?? []).map((c) => c.label))
  for (let i = 0; i < 26; i++) {
    const label = String.fromCharCode(65 + i)
    if (!used.has(label)) return label
  }
  return String(used.size + 1)
}

watch(
  () => [comp.shelfId, comp.column],
  () => {
    if (comp.column === NEW_COLUMN && !comp.newColumnLabel) comp.newColumnLabel = nextColumnLabel()
    if (comp.column !== NEW_COLUMN && targetShelf.value && comp.column >= targetShelf.value.columns.length) comp.column = 0
    comp.name = suggestName()
  },
  { immediate: true },
)
watch(() => comp.newColumnLabel, () => comp.column === NEW_COLUMN && (comp.name = suggestName()))

async function createCompartment() {
  const shelf = targetShelf.value
  if (!shelf || !comp.name.trim()) return
  // Every existing compartment keeps its origin, so no book changes place.
  const columns = shelf.columns.map((c, ci) => ({
    label: c.label,
    rows: c.rows.map((name, ri) => ({ name, from_col: ci + 1, from_row: ri + 1 })),
  }))
  let col
  if (comp.column === NEW_COLUMN) {
    columns.push({ label: comp.newColumnLabel.trim() || nextColumnLabel(), rows: [{ name: comp.name.trim(), from_col: null, from_row: null }] })
    col = columns.length
  } else {
    columns[comp.column].rows.push({ name: comp.name.trim(), from_col: null, from_row: null })
    col = comp.column + 1
  }
  const row = columns[col - 1].rows.length
  await save(() => api('shelves.php', { method: 'PUT', query: { id: shelf.id }, body: { columns } }), { shelf_id: shelf.id, shelf_col: col, shelf_row: row })
}

// ---- new shelf
const shelfForm = reactive({ name: '', room: '', columns: 2, rows: 4 })

async function createShelf() {
  if (!shelfForm.name.trim()) return
  const columns = Array.from({ length: shelfForm.columns }, () => shelfForm.rows) // compact form: [4, 4] -> A1…A4, B1…B4
  let created = null
  await save(async () => {
    const result = await api('shelves.php', { method: 'POST', body: { name: shelfForm.name, room: shelfForm.room, columns } })
    created = result.shelf
    return result
  }, () => ({ shelf_id: created.id, shelf_col: 1, shelf_row: 1 }))
}

async function save(request, position) {
  busy.value = true
  error.value = ''
  try {
    await request()
    await loadShelves({ force: true })
    toast(t('newLocation.created'), 'success')
    emit('created', typeof position === 'function' ? position() : position)
  } catch (e) {
    const code = e instanceof ApiError ? Object.values(e.fields)[0] : null
    error.value = code ? fieldError(code) + (e.fields.duplicate ? ` („${e.fields.duplicate}“)` : '') : errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :title="t('newLocation.title')" @close="emit('close')">
    <div class="space-y-4 text-sm">
      <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1 dark:bg-stone-800" role="tablist">
        <button
          type="button"
          role="tab"
          class="flex items-center justify-center gap-2 rounded-lg px-2 py-2 font-medium"
          :class="mode === 'compartment' ? 'bg-white shadow dark:bg-stone-950' : 'text-stone-600 dark:text-stone-400'"
          :disabled="!shelves.length"
          :aria-selected="mode === 'compartment'"
          @click="mode = 'compartment'"
        >
          <Rows3 class="h-4 w-4" />{{ t('newLocation.compartment') }}
        </button>
        <button
          type="button"
          role="tab"
          class="flex items-center justify-center gap-2 rounded-lg px-2 py-2 font-medium"
          :class="mode === 'shelf' ? 'bg-white shadow dark:bg-stone-950' : 'text-stone-600 dark:text-stone-400'"
          :aria-selected="mode === 'shelf'"
          @click="mode = 'shelf'"
        >
          <LibraryBig class="h-4 w-4" />{{ t('newLocation.shelf') }}
        </button>
      </div>

      <form v-if="mode === 'compartment'" class="space-y-4" @submit.prevent="createCompartment">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="nl_shelf">{{ t('newLocation.shelfLabel') }}</label>
            <select id="nl_shelf" v-model="comp.shelfId" class="input">
              <option v-for="s in shelves" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="nl_column">{{ t('shelves.column') }}</label>
            <select id="nl_column" v-model="comp.column" class="input">
              <option v-for="(c, i) in targetShelf?.columns ?? []" :key="i" :value="i">{{ c.label }} ({{ t('shelves.compartments', { n: c.rows.length }) }})</option>
              <option :value="NEW_COLUMN">{{ t('newLocation.newColumn') }}</option>
            </select>
          </div>
          <div v-if="comp.column === NEW_COLUMN">
            <label class="label" for="nl_col_label">{{ t('shelves.columnLabel') }}</label>
            <input id="nl_col_label" v-model="comp.newColumnLabel" class="input" maxlength="30" required />
          </div>
          <div :class="comp.column === NEW_COLUMN ? '' : 'sm:col-span-2'">
            <label class="label" for="nl_name">{{ t('newLocation.compartmentName') }}</label>
            <input id="nl_name" v-model="comp.name" class="input" maxlength="30" required />
            <p class="mt-1 text-xs text-stone-500">{{ t('newLocation.compartmentHint') }}</p>
          </div>
        </div>
        <p v-if="error" class="field-error">{{ error }}</p>
        <div class="flex justify-end gap-2 border-t border-stone-100 pt-4 dark:border-stone-800">
          <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
          <button class="btn-primary" :disabled="busy || !comp.name.trim()">{{ t('newLocation.createCompartment') }}</button>
        </div>
      </form>

      <form v-else class="space-y-4" @submit.prevent="createShelf">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="nl_shelf_name">{{ t('shelves.name') }} *</label>
            <input id="nl_shelf_name" v-model="shelfForm.name" class="input" maxlength="100" required :placeholder="t('newLocation.shelfPlaceholder')" />
          </div>
          <div>
            <label class="label" for="nl_room">{{ t('shelves.room') }}</label>
            <input id="nl_room" v-model="shelfForm.room" class="input" maxlength="100" />
          </div>
          <div>
            <label class="label" for="nl_cols">{{ t('newLocation.columnCount') }}</label>
            <input id="nl_cols" v-model.number="shelfForm.columns" type="number" min="1" max="20" class="input" required />
          </div>
          <div>
            <label class="label" for="nl_rows">{{ t('newLocation.rowCount') }}</label>
            <input id="nl_rows" v-model.number="shelfForm.rows" type="number" min="1" max="30" class="input" required />
          </div>
        </div>
        <p class="text-xs text-stone-500">{{ t('newLocation.shelfHint') }}</p>
        <p v-if="error" class="field-error">{{ error }}</p>
        <div class="flex justify-end gap-2 border-t border-stone-100 pt-4 dark:border-stone-800">
          <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
          <button class="btn-primary" :disabled="busy || !shelfForm.name.trim()">{{ t('newLocation.createShelf') }}</button>
        </div>
      </form>
    </div>
  </BaseModal>
</template>
