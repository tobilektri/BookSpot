<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Pencil, Trash2, MapPin, CalendarClock, Users, LibraryBig, Check, BookOpen } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast, store } from '../lib/store.js'
import { books as catalog, patchBook, removeBook } from '../lib/catalog.js'
import { savePersonal, moveBooks } from '../lib/sync.js'
import { formatDate, formatPrice, locationText, todayIso } from '../lib/format.js'
import { invalidateShelves } from '../lib/shelves.js'
import BookCover from '../components/BookCover.vue'
import StarRating from '../components/StarRating.vue'
import DueLabel from '../components/DueLabel.vue'
import BaseModal from '../components/BaseModal.vue'
import LocationPicker from '../components/LocationPicker.vue'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const book = ref(null)
const error = ref('')
const offlineCopy = ref(false)
const personal = reactive({ reading_status: 'unread', rating: null, pages_read: 0, started_at: '', finished_at: '', notes: '' })
const dirty = ref(false)
const saving = ref(false)

const showMove = ref(false)
const moveTarget = ref({})
const showLend = ref(false)
const lendName = ref('')
const showExtend = ref(false)
const extendDate = ref('')

async function load() {
  error.value = ''
  try {
    const data = await api('books.php', { query: { id: props.id } })
    book.value = data.book
    offlineCopy.value = !store.online
  } catch (e) {
    const cached = catalog.value.find((b) => b.id === Number(props.id))
    if (cached && e.offline) {
      book.value = cached
      offlineCopy.value = true
    } else {
      error.value = errorMessage(e)
    }
  }
  if (book.value) resetPersonal()
}

function resetPersonal() {
  const p = book.value.personal ?? {}
  Object.assign(personal, {
    reading_status: p.reading_status ?? 'unread',
    rating: p.rating ?? null,
    pages_read: p.pages_read ?? 0,
    started_at: p.started_at ?? '',
    finished_at: p.finished_at ?? '',
    notes: p.notes ?? '',
  })
  dirty.value = false
}

watch(() => props.id, load, { immediate: true })

const progress = computed(() => (book.value?.pages ? Math.min(100, Math.round((personal.pages_read / book.value.pages) * 100)) : 0))

async function persist(data, { quiet = false } = {}) {
  saving.value = true
  try {
    const result = await savePersonal(book.value.id, data)
    book.value = { ...book.value, personal: { ...book.value.personal, ...result.personal } }
    patchBook(book.value)
    resetPersonal()
    if (!quiet || result.queued) toast(result.queued ? t('sync.queued') : t('common.saved'), 'success')
  } catch (e) {
    toast(errorMessage(e), 'error')
  } finally {
    saving.value = false
  }
}

const setStatus = (status) => persist({ reading_status: status }, { quiet: true })
const setRating = (rating) => persist({ rating }, { quiet: true })
const saveDetails = () =>
  persist({
    pages_read: Number(personal.pages_read) || 0,
    started_at: personal.started_at || null,
    finished_at: personal.finished_at || null,
    notes: personal.notes,
  })

async function updateBook(data, message = t('common.saved')) {
  try {
    const result = await api('books.php', { method: 'PUT', query: { id: book.value.id }, body: data })
    book.value = result.book
    patchBook(result.book)
    toast(message, 'success')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

function openMove() {
  moveTarget.value = { shelf_id: book.value.shelf_id, shelf_col: book.value.shelf_col, shelf_row: book.value.shelf_row }
  showMove.value = true
}

async function saveMove() {
  const target = moveTarget.value
  try {
    const result = await moveBooks([book.value.id], target.shelf_id, target.shelf_col, target.shelf_row)
    showMove.value = false
    invalidateShelves()
    if (result.queued) {
      toast(t('sync.queued'), 'success')
      book.value = { ...book.value, ...target }
    } else {
      toast(t('shelves.moved', { n: 1 }), 'success')
      await load()
      patchBook(book.value)
    }
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

async function lend() {
  if (!lendName.value.trim()) return
  await updateBook({ lent_to: lendName.value.trim(), lent_at: todayIso() })
  showLend.value = false
  lendName.value = ''
}

async function extend() {
  await updateBook({ loan_due: extendDate.value })
  showExtend.value = false
}

async function remove() {
  if (!confirm(t('book.deleteConfirm', { title: book.value.title }))) return
  try {
    await api('books.php', { method: 'DELETE', query: { id: book.value.id } })
    removeBook(book.value.id)
    invalidateShelves()
    toast(t('book.deleted'), 'success')
    router.replace('/books')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

const details = computed(() => {
  const b = book.value
  if (!b) return []
  return [
    [t('book.isbn'), [b.isbn13, b.isbn10].filter(Boolean).join(' / ')],
    ['ASIN', b.asin],
    [t('book.publisher'), b.publisher],
    [t('book.year'), b.published_year],
    [t('book.pages'), b.pages || null],
    [t('book.genre'), b.genre],
    [t('book.format'), b.format],
    [t('book.language'), b.language],
    [t('book.price'), b.price ? formatPrice(b.price) : null],
    [t('book.acquired'), b.acquired_at ? formatDate(b.acquired_at) : null],
  ].filter(([, v]) => v !== null && v !== undefined && v !== '')
})

const statuses = ['unread', 'reading', 'read', 'abandoned']
</script>

<template>
  <div class="space-y-4">
    <button class="btn-ghost -ml-2 !px-2" @click="router.back()"><ArrowLeft class="h-4 w-4" />{{ t('common.back') }}</button>

    <p v-if="error" class="card text-sm text-red-700">{{ error }}</p>
    <div v-else-if="!book" class="card text-sm text-stone-500">{{ t('common.loading') }}</div>

    <template v-else>
      <section class="flex flex-col gap-4 sm:flex-row">
        <BookCover :book="book" size="lg" class="self-center sm:self-start" />
        <div class="min-w-0 flex-1">
          <h1 class="font-serif text-2xl leading-tight font-semibold">{{ book.title }}</h1>
          <p class="mt-1 text-stone-600 dark:text-stone-400">{{ book.authors || t('common.unknownAuthor') }}</p>
          <div class="mt-2 flex flex-wrap gap-2 text-xs">
            <span class="rounded-full bg-brand-100 px-2 py-0.5 text-brand-800 dark:bg-brand-900/40 dark:text-brand-300">{{ t(`entry.${book.entry_type}`) }}</span>
            <span v-if="book.genre" class="rounded-full bg-stone-200 px-2 py-0.5 dark:bg-stone-800">{{ book.genre }}</span>
            <span v-if="book.status !== 'active'" class="rounded-full bg-stone-800 px-2 py-0.5 text-white">{{ t(book.status === 'returned' ? 'book.statusReturned' : 'book.statusDiscarded') }}</span>
            <span v-if="offlineCopy" class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900">Offline</span>
          </div>

          <!-- Location (owned books) -->
          <div v-if="book.entry_type === 'owned'" class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 ring-1 ring-stone-200 dark:bg-stone-900 dark:ring-stone-800">
            <MapPin class="h-5 w-5 text-brand-600" />
            <div class="min-w-0 flex-1">
              <div class="text-xs text-stone-500">{{ t('book.location') }}</div>
              <div class="font-medium">{{ locationText(book.location) || t('book.noLocation') }}</div>
            </div>
            <RouterLink v-if="book.shelf_id" :to="{ name: 'shelf', params: { id: book.shelf_id }, query: { col: book.shelf_col, row: book.shelf_row } }" class="btn-ghost !px-2 !py-1.5 text-sm">
              <LibraryBig class="h-4 w-4" />
            </RouterLink>
            <button class="btn-secondary !py-1.5" @click="openMove">{{ book.shelf_id ? t('common.moveTo') : t('book.setLocation') }}</button>
          </div>

          <!-- Loans -->
          <div v-else class="mt-4 rounded-xl bg-white p-3 ring-1 ring-stone-200 dark:bg-stone-900 dark:ring-stone-800">
            <div class="flex items-center gap-2">
              <CalendarClock class="h-5 w-5 text-brand-600" />
              <div class="flex-1">
                <div class="text-xs text-stone-500">{{ t('book.loanFrom') }}</div>
                <div class="font-medium">{{ book.loan_from || '–' }}</div>
              </div>
              <div class="text-right text-sm">
                <DueLabel :book="book" />
                <div v-if="book.loan_due" class="text-xs text-stone-500">{{ formatDate(book.loan_due) }}</div>
              </div>
            </div>
            <div class="mt-2 text-xs text-stone-500">
              <span v-if="book.loan_start">{{ t('book.loanStart') }}: {{ formatDate(book.loan_start) }}</span>
              <span v-if="book.loan_username"> · {{ t('book.borrower') }}: {{ book.loan_username }}</span>
            </div>
            <div v-if="book.status === 'active'" class="mt-3 flex flex-wrap gap-2">
              <button class="btn-secondary !py-1.5" @click="(extendDate = book.loan_due || todayIso(14)), (showExtend = true)">{{ t('due.extend') }}</button>
              <button class="btn-primary !py-1.5" @click="updateBook({ status: 'returned' }, t('due.returned'))"><Check class="h-4 w-4" />{{ t('due.markReturned') }}</button>
            </div>
          </div>

          <!-- Lending own books to someone -->
          <div v-if="book.entry_type === 'owned'" class="mt-2 flex items-center gap-2 text-sm">
            <Users class="h-4 w-4 text-stone-500" />
            <template v-if="book.lent_to">
              <span class="flex-1 text-amber-800 dark:text-amber-300">{{ t('book.lentTo') }} <strong>{{ book.lent_to }}</strong><span v-if="book.lent_at"> · {{ formatDate(book.lent_at) }}</span></span>
              <button class="btn-ghost !py-1 text-sm" @click="updateBook({ lent_to: null })">{{ t('book.gotBack') }}</button>
            </template>
            <button v-else class="btn-ghost !px-1 !py-1 text-sm" @click="showLend = true">{{ t('book.lend') }} …</button>
          </div>
        </div>
      </section>

      <!-- Personal reading data -->
      <section class="card space-y-4">
        <h2 class="flex items-center gap-2 font-semibold"><BookOpen class="h-5 w-5 text-brand-600" />{{ t('book.personal') }}</h2>
        <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1 sm:grid-cols-4 dark:bg-stone-800">
          <button
            v-for="s in statuses"
            :key="s"
            class="rounded-lg px-2 py-2 text-sm font-medium transition"
            :class="personal.reading_status === s ? 'bg-white shadow dark:bg-stone-950' : 'text-stone-600 dark:text-stone-400'"
            :disabled="saving"
            @click="setStatus(s)"
          >
            {{ t(`status.${s}`) }}
          </button>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-sm text-stone-600 dark:text-stone-400">{{ t('book.rating') }}</span>
          <StarRating :model-value="personal.rating" @update:model-value="setRating" />
        </div>
        <form class="space-y-3" @submit.prevent="saveDetails" @input="dirty = true">
          <div>
            <label class="label" for="pages_read">{{ t('book.pagesRead') }}</label>
            <div class="flex items-center gap-3">
              <input id="pages_read" v-model.number="personal.pages_read" type="number" min="0" inputmode="numeric" class="input !w-28" />
              <span class="text-sm text-stone-500">/ {{ book.pages || '?' }}</span>
              <div v-if="book.pages" class="h-2 flex-1 overflow-hidden rounded-full bg-stone-200 dark:bg-stone-800">
                <div class="h-full rounded-full bg-sky-500" :style="{ width: progress + '%' }"></div>
              </div>
            </div>
            <input v-if="book.pages" v-model.number="personal.pages_read" type="range" min="0" :max="book.pages" class="mt-2 w-full accent-brand-600" :aria-label="t('book.pagesRead')" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="started_at">{{ t('book.started') }}</label>
              <input id="started_at" v-model="personal.started_at" type="date" class="input" />
            </div>
            <div>
              <label class="label" for="finished_at">{{ t('book.finished') }}</label>
              <input id="finished_at" v-model="personal.finished_at" type="date" class="input" />
            </div>
          </div>
          <div>
            <label class="label" for="notes">{{ t('book.notes') }}</label>
            <textarea id="notes" v-model="personal.notes" rows="3" class="input"></textarea>
          </div>
          <div v-if="dirty" class="flex justify-end gap-2">
            <button type="button" class="btn-ghost" @click="resetPersonal">{{ t('common.cancel') }}</button>
            <button class="btn-primary" :disabled="saving">{{ t('common.save') }}</button>
          </div>
        </form>
        <div v-if="book.readers?.length" class="border-t border-stone-200 pt-3 text-sm dark:border-stone-800">
          <div class="mb-1 text-xs text-stone-500">{{ t('book.otherReaders') }}</div>
          <div v-for="r in book.readers" :key="r.username" class="flex items-center justify-between">
            <span>{{ r.username }} · {{ t(`status.${r.reading_status}`) }}</span>
            <StarRating v-if="r.rating" :model-value="r.rating" readonly size="h-4 w-4" />
          </div>
        </div>
      </section>

      <section v-if="book.description" class="card">
        <h2 class="mb-2 font-semibold">{{ t('book.description') }}</h2>
        <p class="text-sm leading-relaxed whitespace-pre-line text-stone-700 dark:text-stone-300">{{ book.description }}</p>
      </section>

      <section class="card">
        <h2 class="mb-2 font-semibold">{{ t('book.details') }}</h2>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
          <template v-for="[label, value] in details" :key="label">
            <dt class="text-stone-500">{{ label }}</dt>
            <dd class="break-words">{{ value }}</dd>
          </template>
        </dl>
        <p v-if="book.created_by_name" class="mt-3 text-xs text-stone-500">{{ t('book.addedBy', { name: book.created_by_name }) }} · {{ formatDate(book.created_at) }}</p>
      </section>

      <div class="flex gap-2">
        <RouterLink :to="`/books/${book.id}/edit`" class="btn-secondary flex-1"><Pencil class="h-4 w-4" />{{ t('common.edit') }}</RouterLink>
        <button class="btn-ghost text-red-600" :disabled="!store.online" @click="remove"><Trash2 class="h-4 w-4" />{{ t('common.delete') }}</button>
      </div>
    </template>

    <BaseModal v-if="showMove" :title="t('book.setLocation')" wide @close="showMove = false">
      <LocationPicker v-model="moveTarget" />
      <div class="mt-4 flex justify-end gap-2">
        <button class="btn-secondary" @click="showMove = false">{{ t('common.cancel') }}</button>
        <button class="btn-primary" @click="saveMove">{{ t('common.save') }}</button>
      </div>
    </BaseModal>

    <BaseModal v-if="showLend" :title="t('book.lend')" @close="showLend = false">
      <form class="space-y-3" @submit.prevent="lend">
        <label class="label" for="lend_to">{{ t('book.lendTo') }}</label>
        <input id="lend_to" v-model="lendName" class="input" maxlength="100" required autofocus />
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showLend = false">{{ t('common.cancel') }}</button>
          <button class="btn-primary">{{ t('common.save') }}</button>
        </div>
      </form>
    </BaseModal>

    <BaseModal v-if="showExtend" :title="t('due.extend')" @close="showExtend = false">
      <form class="space-y-3" @submit.prevent="extend">
        <label class="label" for="extend_due">{{ t('book.loanDue') }}</label>
        <input id="extend_due" v-model="extendDate" type="date" class="input" required />
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showExtend = false">{{ t('common.cancel') }}</button>
          <button class="btn-primary">{{ t('common.save') }}</button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
