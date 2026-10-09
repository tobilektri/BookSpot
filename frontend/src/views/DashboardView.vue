<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Search, ScanBarcode, Plus, Upload, CalendarClock, BookOpen, MapPin, Sparkles, Users, Library } from '@lucide/vue'
import { store, toast } from '../lib/store.js'
import { t, errorMessage } from '../lib/i18n.js'
import { api } from '../lib/api.js'
import { books, loadCatalog, patchBook } from '../lib/catalog.js'
import { savePersonal } from '../lib/sync.js'
import { formatNumber, formatDate } from '../lib/format.js'
import BookCover from '../components/BookCover.vue'
import BookRow from '../components/BookRow.vue'
import DueLabel from '../components/DueLabel.vue'
import EnrichButton from '../components/EnrichButton.vue'

const router = useRouter()
const query = ref('')
const loadError = ref('')
const progressDraft = ref({})

onMounted(async () => {
  try {
    await loadCatalog()
  } catch (e) {
    loadError.value = errorMessage(e)
  }
})

const loans = computed(() =>
  books.value
    .filter((b) => b.entry_type !== 'owned' && b.status === 'active')
    .sort((a, b) => (a.loan_due ?? '9999').localeCompare(b.loan_due ?? '9999')),
)
const reading = computed(() => books.value.filter((b) => b.personal?.reading_status === 'reading'))
const lent = computed(() => books.value.filter((b) => b.lent_to))
const unplaced = computed(() => books.value.filter((b) => b.entry_type === 'owned' && !b.shelf_id).length)
const recent = computed(() => [...books.value].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '') || b.id - a.id).slice(0, 8))
const readCount = computed(() => books.value.filter((b) => b.personal?.reading_status === 'read').length)
const pagesRead = computed(() =>
  books.value.reduce((sum, b) => sum + (b.personal?.reading_status === 'read' ? Math.max(b.pages, b.personal.pages_read) : b.personal?.pages_read ?? 0), 0),
)

function search() {
  router.push({ name: 'books', query: query.value ? { q: query.value } : {} })
}

function progressPercent(book) {
  if (!book.pages) return 0
  return Math.min(100, Math.round(((book.personal?.pages_read ?? 0) / book.pages) * 100))
}

async function saveProgress(book) {
  const pages = Number(progressDraft.value[book.id])
  if (!Number.isFinite(pages)) return
  try {
    const data = book.pages && pages >= book.pages ? { pages_read: pages, reading_status: 'read' } : { pages_read: pages }
    const result = await savePersonal(book.id, data)
    patchBook({ ...book, personal: { ...book.personal, ...result.personal } })
    delete progressDraft.value[book.id]
    toast(result.queued ? t('sync.queued') : t('common.saved'), 'success')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="space-y-6">
    <section>
      <h1 class="font-serif text-2xl font-semibold">{{ t('dashboard.hello', { name: store.user?.username }) }}</h1>
      <form class="relative mt-3" @submit.prevent="search">
        <Search class="pointer-events-none absolute top-3 left-3 h-5 w-5 text-stone-400" />
        <input v-model="query" type="search" class="input pl-10" :placeholder="t('dashboard.searchPlaceholder')" enterkeyhint="search" />
      </form>
      <div class="no-scrollbar mt-3 flex gap-2 overflow-x-auto">
        <RouterLink to="/scan" class="btn-primary shrink-0"><ScanBarcode class="h-4 w-4" />{{ t('dashboard.quickScan') }}</RouterLink>
        <RouterLink to="/books/new" class="btn-secondary shrink-0"><Plus class="h-4 w-4" />{{ t('dashboard.quickAdd') }}</RouterLink>
        <RouterLink to="/settings#import" class="btn-secondary shrink-0"><Upload class="h-4 w-4" />{{ t('dashboard.quickImport') }}</RouterLink>
      </div>
    </section>

    <p v-if="loadError" class="card text-sm text-red-700">{{ loadError }}</p>

    <section v-if="!books.length && !loadError" class="card py-10 text-center">
      <Library class="mx-auto h-12 w-12 text-brand-600" />
      <h2 class="mt-3 text-lg font-semibold">{{ t('dashboard.emptyTitle') }}</h2>
      <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ t('dashboard.emptyText') }}</p>
    </section>

    <template v-else>
      <section class="grid grid-cols-3 gap-3">
        <RouterLink to="/books" class="card !p-3 text-center">
          <div class="text-2xl font-semibold">{{ formatNumber(books.length) }}</div>
          <div class="text-xs text-stone-500">{{ t('dashboard.statBooks') }}</div>
        </RouterLink>
        <RouterLink :to="{ name: 'books', query: { status: 'read' } }" class="card !p-3 text-center">
          <div class="text-2xl font-semibold">{{ formatNumber(readCount) }}</div>
          <div class="text-xs text-stone-500">{{ t('dashboard.statRead') }}</div>
        </RouterLink>
        <RouterLink to="/stats" class="card !p-3 text-center">
          <div class="text-2xl font-semibold">{{ formatNumber(pagesRead) }}</div>
          <div class="text-xs text-stone-500">{{ t('dashboard.statPages') }}</div>
        </RouterLink>
      </section>

      <div class="grid gap-6 lg:grid-cols-2">
        <section class="card">
          <h2 class="section-title flex items-center gap-2"><CalendarClock class="h-5 w-5 text-brand-600" />{{ t('dashboard.dueTitle') }}</h2>
          <p v-if="!loans.length" class="text-sm text-stone-500">{{ t('dashboard.dueEmpty') }}</p>
          <ul v-else class="divide-y divide-stone-100 dark:divide-stone-800">
            <li v-for="book in loans" :key="book.id">
              <RouterLink :to="`/books/${book.id}`" class="flex items-center gap-3 py-2">
                <BookCover :book="book" size="sm" />
                <div class="min-w-0 flex-1">
                  <div class="truncate font-medium">{{ book.title }}</div>
                  <div class="truncate text-xs text-stone-500">{{ book.loan_from || t(`entry.${book.entry_type}`) }} · {{ formatDate(book.loan_due) }}</div>
                </div>
                <span
                  class="shrink-0 rounded-lg px-2 py-1 text-xs"
                  :class="book.loan_days_left !== null && book.loan_days_left <= 3 ? 'bg-amber-100 dark:bg-amber-900/40' : 'bg-stone-100 dark:bg-stone-800'"
                ><DueLabel :book="book" /></span>
              </RouterLink>
            </li>
          </ul>
        </section>

        <section class="card">
          <h2 class="section-title flex items-center gap-2"><BookOpen class="h-5 w-5 text-brand-600" />{{ t('dashboard.readingTitle') }}</h2>
          <p v-if="!reading.length" class="text-sm text-stone-500">{{ t('dashboard.readingEmpty') }}</p>
          <ul v-else class="space-y-3">
            <li v-for="book in reading" :key="book.id" class="flex gap-3">
              <RouterLink :to="`/books/${book.id}`"><BookCover :book="book" size="sm" /></RouterLink>
              <div class="min-w-0 flex-1">
                <RouterLink :to="`/books/${book.id}`" class="block truncate font-medium">{{ book.title }}</RouterLink>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-stone-200 dark:bg-stone-800">
                  <div class="h-full rounded-full bg-sky-500" :style="{ width: progressPercent(book) + '%' }"></div>
                </div>
                <form class="mt-1.5 flex items-center gap-2 text-xs text-stone-500" @submit.prevent="saveProgress(book)">
                  <input
                    v-model="progressDraft[book.id]"
                    type="number"
                    min="0"
                    :max="book.pages || undefined"
                    inputmode="numeric"
                    class="input !w-20 !px-2 !py-1 !text-sm"
                    :placeholder="String(book.personal?.pages_read ?? 0)"
                    :aria-label="t('book.pagesRead')"
                  />
                  <span>/ {{ book.pages || '?' }}</span>
                  <button v-if="progressDraft[book.id] !== undefined && progressDraft[book.id] !== ''" class="btn-primary !px-2 !py-1 !text-xs">{{ t('common.save') }}</button>
                </form>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <section v-if="unplaced" class="card flex items-center gap-3 !bg-brand-50 text-sm dark:!bg-brand-900/20">
        <MapPin class="h-5 w-5 shrink-0 text-brand-600" />
        <span class="flex-1">{{ t('dashboard.unplaced', { n: unplaced }) }}</span>
        <RouterLink :to="{ name: 'books', query: { filter: 'unplaced' } }" class="btn-secondary !py-1.5">{{ t('books.filterUnplaced') }}</RouterLink>
      </section>

      <EnrichButton v-if="store.online" compact />

      <section v-if="lent.length" class="card">
        <h2 class="section-title flex items-center gap-2"><Users class="h-5 w-5 text-brand-600" />{{ t('dashboard.lentTitle') }}</h2>
        <BookRow v-for="book in lent" :key="book.id" :book="book" />
      </section>

      <section>
        <h2 class="section-title flex items-center gap-2"><Sparkles class="h-5 w-5 text-brand-600" />{{ t('dashboard.recent') }}</h2>
        <div class="no-scrollbar flex gap-3 overflow-x-auto pb-2">
          <RouterLink v-for="book in recent" :key="book.id" :to="`/books/${book.id}`" class="w-24 shrink-0">
            <BookCover :book="book" class="!h-36 !w-24" />
            <div class="mt-1 line-clamp-2 text-xs font-medium">{{ book.title }}</div>
          </RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>
