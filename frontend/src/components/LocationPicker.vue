<script setup>
import { ref, computed, onMounted } from 'vue'
import { loadShelves, shelves } from '../lib/shelves.js'
import { t } from '../lib/i18n.js'
import ShelfGrid from './ShelfGrid.vue'

/** v-model: { shelf_id, shelf_col, shelf_row } (nulls = no location) */
const model = defineModel({ type: Object, default: () => ({ shelf_id: null, shelf_col: null, shelf_row: null }) })
const loading = ref(true)

onMounted(async () => {
  try {
    await loadShelves()
  } finally {
    loading.value = false
  }
})

const shelf = computed(() => shelves.value.find((s) => s.id === model.value?.shelf_id) ?? null)

const shelfId = computed({
  get: () => model.value?.shelf_id ?? '',
  set: (value) => (model.value = { shelf_id: value === '' ? null : Number(value), shelf_col: null, shelf_row: null }),
})

const compartmentKey = computed({
  get: () => (model.value?.shelf_col ? `${model.value.shelf_col}:${model.value.shelf_row}` : ''),
  set: (value) => {
    const [col, row] = value ? value.split(':').map(Number) : [null, null]
    model.value = { ...model.value, shelf_col: col, shelf_row: row }
  },
})

function pick(col, row) {
  model.value = { ...model.value, shelf_col: col, shelf_row: row }
}

</script>

<template>
  <div class="space-y-3">
    <p v-if="!loading && !shelves.length" class="text-sm text-stone-500">
      {{ t('shelves.empty') }}
      <RouterLink to="/shelves/new" class="font-medium text-brand-700 hover:underline">{{ t('shelves.new') }}</RouterLink>
    </p>
    <div v-else class="grid grid-cols-2 gap-2">
      <select v-model="shelfId" class="input" :aria-label="t('shelves.title')">
        <option value="">{{ t('book.noLocation') }}</option>
        <option v-for="s in shelves" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <select v-model="compartmentKey" class="input" :disabled="!shelf" :aria-label="t('shelves.rows')">
        <option value="">{{ t('shelves.noShelfPosition') }}</option>
        <template v-if="shelf">
          <optgroup v-for="(column, c) in shelf.columns" :key="c" :label="`${t('shelves.column')} ${column.label}`">
            <option v-for="(name, r) in column.rows" :key="r" :value="`${c + 1}:${r + 1}`">{{ name }}</option>
          </optgroup>
        </template>
      </select>
    </div>
    <ShelfGrid v-if="shelf" :shelf="shelf" mode="pick" :selected="model.shelf_col ? { col: model.shelf_col, row: model.shelf_row } : null" @select="pick" />
  </div>
</template>
