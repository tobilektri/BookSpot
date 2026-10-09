<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Search, Plus, CheckSquare, Square, X, MoveRight } from '@lucide/vue'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { books, loadCatalog, loading, fromCache, loadedAt, normalizeText } from '../lib/catalog.js'
import { moveBooks } from '../lib/sync.js'
import { formatDate, formatNumber } from '../lib/format.js'
import { cleanIsbn } from '../lib/isbn.js'
import BookRow from '../components/BookRow.vue'
import BaseModal from '../components/BaseModal.vue'
import LocationPicker from '../components/LocationPicker.vue'
import { invalidateShelves } from '../lib/shelves.js'

const route = useRoute()
const router = useRouter()
const error = ref('')

const q = ref(String(route.query.q ?? ''))
const status = ref(String(route.query.status ?? ''))
const filter = ref(String(route.query.filter ?? ''))
const genre = ref(String(route.query.genre ?? ''))
const sort = ref(String(route.query.sort ?? 'title'))

const pageSize = 60
const visible = ref(pageSize)
const sentinel = ref(null)
let observer = null

const selecting = ref(false)
const selected = ref(new Set())
const showMove = ref(false)
const target = ref({ shelf_id: null, shelf_col: null, shelf_row: null })

onMounted(async () => {
  try {
    await loadCatalog()
  } catch (e) {
    error.value = errorMessage(e)
  }
  observer = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting)) visible.value += pageSize
  })
  watch(sentinel, (el) => el && observer.observe(el), { immediate: true })
})
onUnmounted(() => observer?.disconnect())

// Keep filters in the URL (shareable, back button friendly).
watch([q, status, filter, genre, sort], () => {
  visible.value = pageSize
  const query = { q: q.value || undefined, status: status.value || undefined, filter: filter.value || undefined, genre: genre.value || undefined, sort: sort.value !== 'title' ? sort.value : undefined }
  router.replace({ query })
})

const genres = computed(() => {
  const counts = {}
  for (const b of books.value) if (b.genre) counts[b.genre] = (counts[b.genre] ?? 0) + 1
  return Object.entries(counts).sort((a, b) => b[1] - a[1])
})

const filtered = computed(() => {
  const needle = normalizeText(q.value.trim())
  const isbnNeedle = cleanIsbn(q.value)
  let list = books.value.filter((b) => {
    if (status.value && (b.personal?.reading_status ?? 'unread') !== status.value) return false
    if (filter.value === 'unplaced' && (b.entry_type !== 'owned' || b.shelf_id)) return false
    if (filter.value === 'lent' && !b.lent_to) return false
    if (filter.value === 'library' && b.entry_type === 'owned') return false
    if (genre.value && b.genre !== genre.value) return false
    if (needle) {
      const hay = normalizeText(`${b.title} ${b.authors} ${b.genre ?? ''} ${b.publisher ?? ''} ${b.location?.compartment ?? ''}`)
      const isbnHit = isbnNeedle.length >= 4 && ((b.isbn13 ?? '').includes(isbnNeedle) || (b.isbn10 ?? '').includes(isbnNeedle))
      if (!needle.split(/\s+/).every((word) => hay.includes(word)) && !isbnHit) return false
    }
    return true
  })
  const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true })
  const sorters = {
    title: (a, b) => collator.compare(a.title, b.title),
    authors: (a, b) => collator.compare(a.authors || '~', b.authors || '~') || collator.compare(a.title, b.title),
    recent: (a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '') || b.id - a.id,
    pages: (a, b) => b.pages - a.pages,
    year: (a, b) => (b.published_year ?? 0) - (a.published_year ?? 0),
    due: (a, b) => (a.loan_due ?? '9999').localeCompare(b.loan_due ?? '9999'),
  }
  return [...list].sort(sorters[sort.value] ?? sorters.title)
})

const statusChips = computed(() => [
  { value: '', label: t('common.all') },
  { value: 'unread', label: t('status.unread') },
  { value: 'reading', label: t('status.reading') },
  { value: 'read', label: t('status.read') },
  { value: 'abandoned', label: t('status.abandoned') },
])
const filterChips = computed(() => [
  { value: 'unplaced', label: t('books.filterUnplaced') },
  { value: 'library', label: t('books.filterLibrary') },
  { value: 'lent', label: t('books.filterLent') },
])

function toggle(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}

/** In selection mode a click selects instead of opening the book. */
function onSelectClick(event, id) {
  event.preventDefault()
  event.stopPropagation()
  toggle(id)
}

function stopSelecting() {
  selecting.value = false
  selected.value = new Set()
}

async function moveSelection() {
  const ids = [...selected.value]
  try {
    const result = await moveBooks(ids, target.value.shelf_id, target.value.shelf_col, target.value.shelf_row)
    toast(result.queued ? t('sync.queued') : t('shelves.moved', { n: ids.length }), 'success')
    showMove.value = false
    stopSelecting()
    invalidateShelves()
    await loadCatalog({ force: true })
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-2">
      <h1 class="font-serif text-2xl font-semibold">{{ t('books.title') }}</h1>
      <div class="flex gap-2">
        <button class="btn-ghost" @click="selecting ? stopSelecting() : (selecting = true)">
          <component :is="selecting ? X : CheckSquare" class="h-4 w-4" />
          <span class="hidden sm:inline">{{ selecting ? t('common.cancel') : t('books.selectMode') }}</span>
        </button>
        <RouterLink to="/books/new" class="btn-primary"><Plus class="h-4 w-4" /><span class="hidden sm:inline">{{ t('common.add') }}</span></RouterLink>
      </div>
    </div>

    <div class="relative">
      <Search class="pointer-events-none absolute top-3 left-3 h-5 w-5 text-stone-400" />
      <input v-model="q" type="search" class="input pl-10" :placeholder="t('dashboard.searchPlaceholder')" enterkeyhint="search" />
    </div>

    <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
      <button v-for="chip in statusChips" :key="chip.value" class="chip" :class="{ 'chip-active': status === chip.value }" @click="status = chip.value">{{ chip.label }}</button>
      <span class="mx-1 w-px shrink-0 bg-stone-300 dark:bg-stone-700"></span>
      <button v-for="chip in filterChips" :key="chip.value" class="chip" :class="{ 'chip-active': filter === chip.value }" @click="filter = filter === chip.value ? '' : chip.value">{{ chip.label }}</button>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <select v-model="genre" class="input !py-2 text-sm" :aria-label="t('books.genre')">
        <option value="">{{ t('books.allGenres') }}</option>
        <option v-for="[g, n] in genres" :key="g" :value="g">{{ g }} ({{ n }})</option>
      </select>
      <select v-model="sort" class="input !py-2 text-sm" :aria-label="t('books.sort')">
        <option value="title">{{ t('books.sortTitle') }}</option>
        <option value="authors">{{ t('books.sortAuthors') }}</option>
        <option value="recent">{{ t('books.sortRecent') }}</option>
        <option value="pages">{{ t('books.sortPages') }}</option>
        <option value="year">{{ t('books.sortYear') }}</option>
        <option value="due">{{ t('books.sortDue') }}</option>
      </select>
    </div>

    <div class="flex items-center justify-between text-sm text-stone-500">
      <span>{{ t('books.count', { n: formatNumber(filtered.length) }) }}</span>
      <span v-if="fromCache && loadedAt">{{ t('books.offlineList', { date: formatDate(loadedAt) }) }}</span>
      <span v-else-if="loading">{{ t('common.loading') }}</span>
    </div>

    <p v-if="error" class="card text-sm text-red-700">{{ error }}</p>
    <p v-else-if="!filtered.length && !loading" class="card text-center text-sm text-stone-500">{{ t('books.empty') }}</p>

    <ul class="grid grid-cols-1 gap-1 md:grid-cols-2">
      <li v-for="book in filtered.slice(0, visible)" :key="book.id" class="flex items-center" @click.capture="selecting && onSelectClick($event, book.id)">
        <button v-if="selecting" class="p-2 text-brand-700" :aria-pressed="selected.has(book.id)">
          <component :is="selected.has(book.id) ? CheckSquare : Square" class="h-5 w-5" />
        </button>
        <BookRow :book="book" class="min-w-0 flex-1" />
      </li>
    </ul>
    <div v-if="visible < filtered.length" ref="sentinel" class="py-4 text-center">
      <button class="btn-secondary" @click="visible += pageSize">{{ t('common.more') }}</button>
    </div>

    <div v-if="selecting && selected.size" class="fixed inset-x-4 bottom-24 z-30 mx-auto flex max-w-md items-center justify-between rounded-xl bg-stone-900 px-4 py-3 text-white shadow-xl md:bottom-6">
      <span class="text-sm">{{ t('books.selected', { n: selected.size }) }}</span>
      <button class="btn-primary !py-1.5" @click="showMove = true"><MoveRight class="h-4 w-4" />{{ t('common.moveTo') }}</button>
    </div>

    <BaseModal v-if="showMove" :title="t('books.moveSelected')" wide @close="showMove = false">
      <LocationPicker v-model="target" />
      <div class="mt-4 flex justify-end gap-2">
        <button class="btn-secondary" @click="showMove = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" @click="moveSelection">{{ t('common.save') }}</button>
      </div>
    </BaseModal>
  </div>
</template>
