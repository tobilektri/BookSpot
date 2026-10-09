<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Plus, Minus, Trash2, Wand2 } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { invalidateShelves } from '../lib/shelves.js'
import { invalidateCatalog } from '../lib/catalog.js'
import ShelfGrid from '../components/ShelfGrid.vue'

const props = defineProps({ id: { type: String, default: null } })
const router = useRouter()
const isEdit = computed(() => Boolean(props.id))
const form = reactive({ name: '', room: '', description: '' })
const columns = ref([
  { label: 'A', rows: ['A1', 'A2', 'A3', 'A4'] },
  { label: 'B', rows: ['B1', 'B2'] },
])
const errors = ref({})
const busy = ref(false)

onMounted(async () => {
  if (!isEdit.value) return
  try {
    const { shelf } = await api('shelves.php', { query: { id: props.id } })
    Object.assign(form, { name: shelf.name, room: shelf.room ?? '', description: shelf.description ?? '' })
    columns.value = shelf.columns.map((c) => ({ label: c.label, rows: [...c.rows] }))
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
})

function nextLabel() {
  const used = new Set(columns.value.map((c) => c.label))
  for (let i = 0; i < 26; i++) {
    const label = String.fromCharCode(65 + i)
    if (!used.has(label)) return label
  }
  return String(columns.value.length + 1)
}

function addColumn() {
  const label = nextLabel()
  columns.value.push({ label, rows: [1, 2, 3, 4].map((n) => `${label}${n}`) })
}

function setRowCount(column, count) {
  count = Math.max(1, Math.min(60, count))
  while (column.rows.length < count) column.rows.push(`${column.label}${column.rows.length + 1}`)
  column.rows.length = count
}

/** Re-labels compartments as <column label><number>, e.g. L1, L2, … */
function autoName(column) {
  column.rows = column.rows.map((_, i) => `${column.label}${i + 1}`)
}

function renameColumn(column, label) {
  const old = column.label
  column.label = label
  // Keep auto-generated names in sync with the column label.
  column.rows = column.rows.map((name, i) => (name === `${old}${i + 1}` ? `${label}${i + 1}` : name))
}

const duplicate = computed(() => {
  const seen = new Set()
  for (const name of columns.value.flatMap((c) => c.rows)) {
    const key = name.replace(/\s+/g, '').toLowerCase()
    if (seen.has(key)) return name
    seen.add(key)
  }
  return null
})

const preview = computed(() => ({ columns: columns.value.map((c) => ({ ...c, counts: [] })) }))

async function submit() {
  busy.value = true
  errors.value = {}
  try {
    const body = { ...form, columns: columns.value }
    const result = isEdit.value ? await api('shelves.php', { method: 'PUT', query: { id: props.id }, body }) : await api('shelves.php', { method: 'POST', body })
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
    await api('shelves.php', { method: 'DELETE', query: { id: props.id } })
    invalidateShelves()
    invalidateCatalog()
    router.replace('/shelves')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4">
    <button class="btn-ghost -ml-2 !px-2" @click="router.back()"><ArrowLeft class="h-4 w-4" />{{ t('common.back') }}</button>
    <h1 class="font-serif text-2xl font-semibold">{{ isEdit ? t('shelves.edit') : t('shelves.new') }}</h1>

    <form class="space-y-4" @submit.prevent="submit">
      <section class="card grid gap-3 sm:grid-cols-2">
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
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">{{ t('shelves.columns', { n: columns.length }) }}</h2>
          <button type="button" class="btn-secondary !py-1.5" :disabled="columns.length >= 40" @click="addColumn"><Plus class="h-4 w-4" />{{ t('shelves.addColumn') }}</button>
        </div>
        <p class="text-xs text-stone-500">{{ t('shelves.compartmentNames') }}</p>

        <div class="no-scrollbar flex gap-3 overflow-x-auto pb-2">
          <div v-for="(column, c) in columns" :key="c" class="w-44 shrink-0 space-y-2 rounded-xl bg-stone-100 p-3 dark:bg-stone-800">
            <div class="flex items-center gap-1">
              <input
                :value="column.label"
                class="input !px-2 !py-1.5 font-semibold"
                maxlength="30"
                :aria-label="t('shelves.columnLabel')"
                @input="renameColumn(column, $event.target.value)"
              />
              <button type="button" class="btn-ghost !p-1.5 text-red-600" :disabled="columns.length === 1" :aria-label="t('shelves.removeColumn')" @click="columns.splice(c, 1)">
                <Trash2 class="h-4 w-4" />
              </button>
            </div>
            <div class="flex items-center justify-between text-sm">
              <span>{{ t('shelves.rows') }}</span>
              <div class="flex items-center gap-1">
                <button type="button" class="btn-secondary !p-1" :aria-label="'-'" @click="setRowCount(column, column.rows.length - 1)"><Minus class="h-3.5 w-3.5" /></button>
                <span class="w-6 text-center font-semibold">{{ column.rows.length }}</span>
                <button type="button" class="btn-secondary !p-1" :aria-label="'+'" @click="setRowCount(column, column.rows.length + 1)"><Plus class="h-3.5 w-3.5" /></button>
              </div>
            </div>
            <input v-for="(name, r) in column.rows" :key="r" v-model="column.rows[r]" class="input !px-2 !py-1 !text-sm" maxlength="30" :aria-label="`${column.label} ${r + 1}`" />
            <button type="button" class="btn-ghost w-full !py-1 text-xs" @click="autoName(column)"><Wand2 class="h-3.5 w-3.5" />{{ t('shelves.autoNames') }}</button>
          </div>
        </div>
        <p v-if="duplicate" class="field-error">{{ t('shelves.duplicateName', { name: duplicate }) }}</p>
        <p v-else-if="errors.columns" class="field-error">{{ fieldError(errors.columns) }}</p>
      </section>

      <section class="card">
        <h2 class="mb-2 font-semibold">{{ t('shelves.preview') }}</h2>
        <ShelfGrid :shelf="preview" mode="pick" />
      </section>

      <div class="flex items-center justify-between gap-2">
        <button v-if="isEdit" type="button" class="btn-ghost text-red-600" @click="remove"><Trash2 class="h-4 w-4" />{{ t('common.delete') }}</button>
        <span v-else></span>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" @click="router.back()">{{ t('common.cancel') }}</button>
          <button class="btn-primary" :disabled="busy || Boolean(duplicate)">{{ t('common.save') }}</button>
        </div>
      </div>
    </form>
  </div>
</template>
