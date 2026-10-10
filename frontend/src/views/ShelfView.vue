<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Pencil, Plus, X, Search } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { moveBooks } from '../lib/sync.js'
import { books as catalog, loadCatalog, normalizeText } from '../lib/catalog.js'
import { invalidateShelves } from '../lib/shelves.js'
import ShelfGrid from '../components/ShelfGrid.vue'
import BookRow from '../components/BookRow.vue'
import BaseModal from '../components/BaseModal.vue'

const props = defineProps({ id: { type: String, required: true } })
const route = useRoute()
const router = useRouter()
const shelf = ref(null)
const error = ref('')
const selected = ref(route.query.col ? { col: Number(route.query.col), row: Number(route.query.row) } : null)
const showAdd = ref(false)
const panel = ref(null)
const addQuery = ref('')

async function load() {
  try {
    shelf.value = (await api(`v1/shelves/${props.id}`)).shelf
  } catch (e) {
    error.value = errorMessage(e)
  }
}
watch(() => props.id, load, { immediate: true })

const compartmentBooks = computed(() => {
  if (!shelf.value || !selected.value) return []
  return shelf.value.books.filter((b) => b.shelf_col === selected.value.col && b.shelf_row === selected.value.row)
})
const looseBooks = computed(() => shelf.value?.books.filter((b) => b.shelf_col === null) ?? [])
const selectedName = computed(() => (selected.value ? shelf.value?.columns[selected.value.col - 1]?.rows[selected.value.row - 1] : ''))

function select(col, row) {
  selected.value = selected.value?.col === col && selected.value?.row === row ? null : { col, row }
  router.replace({ query: selected.value ? { col, row } : {} })
  if (selected.value) nextTick(() => panel.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
}

async function move(ids, shelfId, col, row) {
  try {
    const result = await moveBooks(ids, shelfId, col, row)
    toast(result.queued ? t('sync.queued') : t('shelves.moved', { n: ids.length }), 'success')
    invalidateShelves()
    await Promise.all([load(), loadCatalog({ force: true }).catch(() => {})])
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

const onDrop = (ids, col, row) => move(ids, Number(props.id), col, row)

const candidates = computed(() => {
  const needle = normalizeText(addQuery.value)
  return catalog.value
    .filter((b) => b.entry_type === 'owned' && b.status === 'active')
    .filter((b) => !(b.shelf_id === Number(props.id) && b.shelf_col === selected.value?.col && b.shelf_row === selected.value?.row))
    .filter((b) => !needle || normalizeText(`${b.title} ${b.authors} ${b.isbn13 ?? ''}`).includes(needle))
    .sort((a, b) => Number(Boolean(a.shelf_id)) - Number(Boolean(b.shelf_id)) || a.title.localeCompare(b.title))
    .slice(0, 50)
})

function openAdd() {
  addQuery.value = ''
  showAdd.value = true
  loadCatalog().catch(() => {})
}

async function addBook(book) {
  await move([book.id], Number(props.id), selected.value.col, selected.value.row)
}
</script>

<template>
  <div class="space-y-4">
    <button class="btn-ghost -ml-2 !px-2" @click="router.push('/shelves')"><ArrowLeft class="h-4 w-4" />{{ t('shelves.title') }}</button>
    <p v-if="error" class="card text-sm text-red-700">{{ error }}</p>

    <template v-if="shelf">
      <div class="flex items-start justify-between gap-2">
        <div>
          <h1 class="font-serif text-2xl font-semibold">{{ shelf.name }}</h1>
          <p class="text-sm text-stone-500"><span v-if="shelf.room">{{ shelf.room }} · </span>{{ t('common.books', { n: shelf.book_count }) }}</p>
          <p v-if="shelf.description" class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ shelf.description }}</p>
        </div>
        <RouterLink :to="`/shelves/${shelf.id}/edit`" class="btn-secondary"><Pencil class="h-4 w-4" /><span class="hidden sm:inline">{{ t('common.edit') }}</span></RouterLink>
      </div>

      <ShelfGrid :shelf="shelf" :books="shelf.books" :selected="selected" @select="select" @drop="onDrop" />

      <section v-if="selected" ref="panel" class="card scroll-mt-20">
        <div class="mb-2 flex items-center justify-between gap-2">
          <h2 class="font-semibold">{{ t('shelves.compartmentBooks', { name: selectedName }) }}</h2>
          <div class="flex gap-1">
            <button class="btn-primary !py-1.5" @click="openAdd"><Plus class="h-4 w-4" />{{ t('shelves.addBooks') }}</button>
            <button class="btn-ghost !p-2" :aria-label="t('common.close')" @click="select(selected.col, selected.row)"><X class="h-4 w-4" /></button>
          </div>
        </div>
        <p v-if="!compartmentBooks.length" class="text-sm text-stone-500">{{ t('shelves.emptyCompartment') }}</p>
        <ul class="divide-y divide-stone-100 dark:divide-stone-800">
          <li v-for="book in compartmentBooks" :key="book.id" class="flex items-center gap-2">
            <BookRow :book="{ ...book, personal: { reading_status: book.reading_status } }" :show-location="false" class="min-w-0 flex-1" />
            <button class="btn-ghost !p-2 text-stone-500" :title="t('shelves.removeFromShelf')" :aria-label="t('shelves.removeFromShelf')" @click="move([book.id], null, null, null)">
              <X class="h-4 w-4" />
            </button>
          </li>
        </ul>
      </section>

      <section v-if="looseBooks.length" class="card">
        <h2 class="mb-2 font-semibold">{{ t('shelves.noShelfPosition') }}</h2>
        <BookRow v-for="book in looseBooks" :key="book.id" :book="{ ...book, personal: { reading_status: book.reading_status } }" :show-location="false" />
      </section>
    </template>

    <BaseModal v-if="showAdd" :title="`${t('shelves.addBooks')} – ${selectedName}`" @close="showAdd = false">
      <div class="relative mb-3">
        <Search class="pointer-events-none absolute top-3 left-3 h-5 w-5 text-stone-400" />
        <input v-model="addQuery" type="search" class="input pl-10" :placeholder="t('shelves.searchUnplaced')" autofocus />
      </div>
      <ul class="divide-y divide-stone-100 dark:divide-stone-800">
        <li v-for="book in candidates" :key="book.id">
          <button class="flex w-full items-center gap-3 py-2 text-left hover:bg-stone-50 dark:hover:bg-stone-800" @click="addBook(book)">
            <Plus class="h-4 w-4 shrink-0 text-brand-600" />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-medium">{{ book.title }}</span>
              <span class="block truncate text-xs text-stone-500">{{ book.authors }}<template v-if="book.location"> · {{ book.location.compartment || book.location.shelf }}</template></span>
            </span>
          </button>
        </li>
      </ul>
    </BaseModal>
  </div>
</template>
