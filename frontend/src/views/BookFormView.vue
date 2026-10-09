<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Download, BookMarked, Library, Users } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError } from '../lib/i18n.js'
import { toast, store } from '../lib/store.js'
import { patchBook, books as catalog } from '../lib/catalog.js'
import { normalizeIsbn } from '../lib/isbn.js'
import { todayIso } from '../lib/format.js'
import { invalidateShelves } from '../lib/shelves.js'
import LocationPicker from '../components/LocationPicker.vue'
import BookCover from '../components/BookCover.vue'

const props = defineProps({ id: { type: String, default: null } })
const route = useRoute()
const router = useRouter()
const isEdit = computed(() => Boolean(props.id))

const form = reactive({
  isbn: '',
  title: '',
  authors: '',
  publisher: '',
  published_year: '',
  pages: '',
  genre: '',
  language: '',
  format: '',
  price: '',
  acquired_at: '',
  cover_url: '',
  description: '',
  entry_type: 'owned',
  loan_from: '',
  loan_start: todayIso(),
  loan_due: todayIso(28),
  lent_to: '',
  status: 'active',
})
const location = ref({ shelf_id: null, shelf_col: null, shelf_row: null })
const initialStatus = ref('unread')
const errors = ref({})
const duplicateId = ref(null)
const busy = ref(false)
const lookupState = ref('') // '', 'loading', 'found', 'none'
const lookupSource = ref('')
const loaded = ref(!props.id)

const genreOptions = computed(() => [...new Set(catalog.value.map((b) => b.genre).filter(Boolean))].sort())

onMounted(async () => {
  if (isEdit.value) {
    try {
      const { book } = await api('books.php', { query: { id: props.id } })
      for (const key of Object.keys(form)) {
        if (key === 'isbn') form.isbn = book.isbn13 ?? book.isbn10 ?? ''
        else if (book[key] !== undefined && book[key] !== null) form[key] = book[key]
        else if (!['entry_type', 'status', 'loan_start', 'loan_due'].includes(key)) form[key] = ''
      }
      if (book.entry_type !== 'library_loan' && !book.loan_due) form.loan_due = ''
      location.value = { shelf_id: book.shelf_id, shelf_col: book.shelf_col, shelf_row: book.shelf_row }
    } catch (e) {
      toast(errorMessage(e), 'error')
    } finally {
      loaded.value = true
    }
  } else if (route.query.isbn) {
    form.isbn = String(route.query.isbn)
    fetchMetadata()
  }
})

async function fetchMetadata() {
  const isbn = normalizeIsbn(form.isbn)
  if (!isbn) {
    errors.value = { ...errors.value, isbn: 'invalid_isbn' }
    return
  }
  errors.value = {}
  lookupState.value = 'loading'
  try {
    const { found, metadata, unavailable } = await api('lookup.php', { query: { isbn } })
    if (!found) {
      lookupState.value = unavailable ? 'unavailable' : 'none'
      return
    }
    for (const key of ['title', 'authors', 'publisher', 'published_year', 'pages', 'genre', 'cover_url', 'description', 'language']) {
      if (metadata[key] && !form[key]) form[key] = metadata[key]
    }
    form.isbn = metadata.isbn13 ?? isbn
    const sourceNames = { open_library: 'Open Library', dnb: 'Deutsche Nationalbibliothek', google_books: 'Google Books' }
    lookupSource.value = (metadata.sources ?? []).map((s) => sourceNames[s] ?? s).join(' + ')
    lookupState.value = 'found'
  } catch (e) {
    lookupState.value = 'none'
    toast(errorMessage(e), 'error')
  }
}

const payload = computed(() => {
  const data = { ...form, ...location.value }
  if (data.entry_type !== 'owned') {
    data.shelf_id = null
    data.shelf_col = null
    data.shelf_row = null
    data.lent_to = ''
  } else {
    delete data.loan_from
    delete data.loan_start
    delete data.loan_due
  }
  if (!isEdit.value) {
    delete data.status
    data.personal = { reading_status: initialStatus.value }
  }
  return data
})

async function submit() {
  busy.value = true
  errors.value = {}
  duplicateId.value = null
  try {
    const result = isEdit.value
      ? await api('books.php', { method: 'PUT', query: { id: props.id }, body: payload.value })
      : await api('books.php', { method: 'POST', body: payload.value })
    patchBook(result.book)
    invalidateShelves()
    toast(isEdit.value ? t('common.saved') : t('book.created'), 'success')
    router.replace(`/books/${result.book.id}`)
    // A loan with a due date is the moment where reminders make most sense.
    if (result.book.entry_type !== 'owned' && result.book.loan_due) store.pushOffer = 'loan'
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.fields
      if (e.code === 'duplicate_isbn') duplicateId.value = e.details.book_id
    }
    toast(errorMessage(e), 'error')
  } finally {
    busy.value = false
  }
}

const types = computed(() => [
  { value: 'owned', label: t('entry.owned'), hint: t('entry.ownedHint'), icon: BookMarked },
  { value: 'library_loan', label: t('entry.library_loan'), hint: t('entry.libraryHint'), icon: Library },
  { value: 'personal_loan', label: t('entry.personal_loan'), hint: t('entry.personalHint'), icon: Users },
])
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <button class="btn-ghost -ml-2 !px-2" @click="router.back()"><ArrowLeft class="h-4 w-4" />{{ t('common.back') }}</button>
    <h1 class="font-serif text-2xl font-semibold">{{ isEdit ? t('book.editTitle') : t('book.newTitle') }}</h1>

    <form v-if="loaded" class="space-y-4" novalidate @submit.prevent="submit">
      <section class="card space-y-3">
        <div>
          <label class="label" for="isbn">{{ t('book.isbn') }}</label>
          <div class="flex gap-2">
            <input id="isbn" v-model="form.isbn" class="input" inputmode="numeric" autocomplete="off" placeholder="978…" />
            <button type="button" class="btn-secondary shrink-0" :disabled="!form.isbn || lookupState === 'loading'" @click="fetchMetadata">
              <Download class="h-4 w-4" />{{ t('book.fetch') }}
            </button>
          </div>
          <p v-if="errors.isbn" class="field-error">{{ fieldError(errors.isbn) }}</p>
          <p v-if="lookupState === 'loading'" class="mt-1 text-sm text-stone-500">{{ t('book.fetching') }}</p>
          <p v-else-if="lookupState === 'found'" class="mt-1 text-sm text-emerald-700 dark:text-emerald-400">{{ t('book.fetched', { source: lookupSource }) }}</p>
          <p v-else-if="lookupState === 'none'" class="mt-1 text-sm text-amber-700">{{ t('book.notFoundOnline') }}</p>
          <p v-else-if="lookupState === 'unavailable'" class="mt-1 text-sm text-amber-700">{{ t('book.lookupUnavailable') }}</p>
          <p v-if="duplicateId" class="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
            {{ t('book.exists') }} <RouterLink :to="`/books/${duplicateId}`" class="font-semibold underline">{{ t('book.openExisting') }}</RouterLink>
          </p>
        </div>

        <div class="flex gap-4">
          <BookCover :book="{ title: form.title || '?', authors: form.authors, cover_url: form.cover_url, isbn13: normalizeIsbn(form.isbn) }" />
          <div class="flex-1 space-y-3">
            <div>
              <label class="label" for="title">{{ t('book.title') }} *</label>
              <input id="title" v-model="form.title" class="input" required maxlength="500" />
              <p v-if="errors.title" class="field-error">{{ fieldError(errors.title) }}</p>
            </div>
            <div>
              <label class="label" for="authors">{{ t('book.authors') }}</label>
              <input id="authors" v-model="form.authors" class="input" maxlength="255" />
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div>
            <label class="label" for="pages">{{ t('book.pages') }}</label>
            <input id="pages" v-model="form.pages" type="number" min="0" inputmode="numeric" class="input" />
          </div>
          <div>
            <label class="label" for="year">{{ t('book.year') }}</label>
            <input id="year" v-model="form.published_year" type="number" min="0" max="2999" inputmode="numeric" class="input" />
          </div>
          <div class="col-span-2 sm:col-span-1">
            <label class="label" for="genre">{{ t('book.genre') }}</label>
            <input id="genre" v-model="form.genre" class="input" list="genre-options" maxlength="100" />
            <datalist id="genre-options"><option v-for="g in genreOptions" :key="g" :value="g" /></datalist>
          </div>
          <div class="col-span-2">
            <label class="label" for="publisher">{{ t('book.publisher') }}</label>
            <input id="publisher" v-model="form.publisher" class="input" maxlength="150" />
          </div>
          <div>
            <label class="label" for="format">{{ t('book.format') }}</label>
            <input id="format" v-model="form.format" class="input" list="format-options" maxlength="30" />
            <datalist id="format-options"><option value="Taschenbuch" /><option value="Hardcover" /><option value="E-Book" /><option value="Hörbuch" /></datalist>
          </div>
        </div>
      </section>

      <section class="card space-y-3">
        <h2 class="font-semibold">{{ t('book.type') }}</h2>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
          <label
            v-for="type in types"
            :key="type.value"
            class="flex cursor-pointer gap-3 rounded-xl border-2 p-3 transition"
            :class="form.entry_type === type.value ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-stone-200 dark:border-stone-800'"
          >
            <input v-model="form.entry_type" type="radio" name="entry_type" :value="type.value" class="sr-only" />
            <component :is="type.icon" class="h-5 w-5 shrink-0 text-brand-600" />
            <span>
              <span class="block text-sm font-medium">{{ type.label }}</span>
              <span class="block text-xs text-stone-500">{{ type.hint }}</span>
            </span>
          </label>
        </div>

        <template v-if="form.entry_type === 'owned'">
          <div>
            <div class="label">{{ t('book.location') }}</div>
            <LocationPicker v-model="location" />
            <p v-if="errors.shelf_row || errors.shelf_id" class="field-error">{{ fieldError(errors.shelf_row || errors.shelf_id) }}</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="price">{{ t('book.price') }}</label>
              <input id="price" v-model="form.price" class="input" inputmode="decimal" placeholder="0,00" />
            </div>
            <div>
              <label class="label" for="acquired_at">{{ t('book.acquired') }}</label>
              <input id="acquired_at" v-model="form.acquired_at" type="date" class="input" />
            </div>
          </div>
          <div v-if="isEdit">
            <label class="label" for="lent_to">{{ t('book.lentTo') }} <span class="font-normal text-stone-500">({{ t('common.optional') }})</span></label>
            <input id="lent_to" v-model="form.lent_to" class="input" maxlength="100" />
          </div>
        </template>
        <div v-else class="grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="label" for="loan_from">{{ form.entry_type === 'library_loan' ? t('book.library') : t('book.person') }}</label>
            <input id="loan_from" v-model="form.loan_from" class="input" maxlength="150" :placeholder="form.entry_type === 'library_loan' ? 'Stadtbibliothek' : ''" />
          </div>
          <div>
            <label class="label" for="loan_start">{{ t('book.loanStart') }}</label>
            <input id="loan_start" v-model="form.loan_start" type="date" class="input" />
          </div>
          <div>
            <label class="label" for="loan_due">{{ t('book.loanDue') }}{{ form.entry_type === 'library_loan' ? ' *' : '' }}</label>
            <input id="loan_due" v-model="form.loan_due" type="date" class="input" :required="form.entry_type === 'library_loan'" />
            <p v-if="errors.loan_due" class="field-error">{{ fieldError(errors.loan_due) }}</p>
          </div>
        </div>

        <div v-if="isEdit">
          <label class="label" for="status">{{ t('book.status') }}</label>
          <select id="status" v-model="form.status" class="input">
            <option value="active">{{ t('book.statusActive') }}</option>
            <option value="returned">{{ t('book.statusReturned') }}</option>
            <option value="discarded">{{ t('book.statusDiscarded') }}</option>
          </select>
        </div>
        <div v-else>
          <label class="label" for="initial_status">{{ t('book.initialStatus') }}</label>
          <select id="initial_status" v-model="initialStatus" class="input">
            <option v-for="s in ['unread', 'reading', 'read']" :key="s" :value="s">{{ t(`status.${s}`) }}</option>
          </select>
        </div>
      </section>

      <section class="card space-y-3">
        <div>
          <label class="label" for="cover_url">{{ t('book.cover') }}</label>
          <input id="cover_url" v-model="form.cover_url" type="url" class="input" placeholder="https://…" />
          <p v-if="errors.cover_url" class="field-error">{{ fieldError(errors.cover_url) }}</p>
        </div>
        <div>
          <label class="label" for="description">{{ t('book.description') }}</label>
          <textarea id="description" v-model="form.description" rows="5" class="input"></textarea>
        </div>
      </section>

      <div class="sticky bottom-20 z-10 flex justify-end gap-2 md:bottom-4">
        <button type="button" class="btn-secondary" @click="router.back()">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="busy">{{ t('common.save') }}</button>
      </div>
    </form>
  </div>
</template>
