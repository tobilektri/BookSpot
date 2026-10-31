<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Gift, Plus, ShoppingBag, Library, Trash2, Lock, Users, Link2, Copy, RefreshCw } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast, store } from '../lib/store.js'
import { patchBook } from '../lib/catalog.js'
import BookCover from '../components/BookCover.vue'

const router = useRouter()
const items = ref([])
const sharing = ref({ visibility: 'private', token: null })
const others = ref([])
const loading = ref(true)
const error = ref('')

async function load() {
  error.value = ''
  try {
    const [mine, shared] = await Promise.all([api('v1/wishlist'), api('v1/wishlists')])
    items.value = mine.items
    sharing.value = mine.sharing
    others.value = shared.lists
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const publicLink = computed(() => (sharing.value.token ? new URL(`#/w/${sharing.value.token}`, document.baseURI).href : ''))

async function share(visibility, renew = false) {
  if (renew && !confirm(t('wishlist.renewConfirm'))) return
  try {
    sharing.value = (await api('v1/wishlist/sharing', { method: 'PUT', body: { visibility, renew } })).sharing
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(publicLink.value)
    toast(t('wishlist.copied'), 'success')
  } catch {
    window.prompt(t('wishlist.copyManually'), publicLink.value)
  }
}

async function fulfil(book, type) {
  try {
    const result = await api(`v1/wishlist/${book.id}/fulfil`, { method: 'POST', body: { entry_type: type } })
    patchBook(result.book)
    toast(t(type === 'owned' ? 'wishlist.bought' : 'wishlist.borrowed'), 'success')
    router.push({ path: `/books/${book.id}`, query: type === 'owned' ? { place: '1' } : {} })
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

async function remove(book) {
  if (!confirm(t('wishlist.removeConfirm', { title: book.title }))) return
  try {
    items.value = (await api(`v1/wishlist/${book.id}`, { method: 'DELETE' })).items
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

const visibilities = computed(() => [
  { value: 'private', label: t('wishlist.private'), hint: t('wishlist.privateHint'), icon: Lock },
  { value: 'instance', label: t('wishlist.instance'), hint: t('wishlist.instanceHint'), icon: Users },
  { value: 'public', label: t('wishlist.public'), hint: t('wishlist.publicHint'), icon: Link2 },
])
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-2">
      <h1 class="flex items-center gap-2 font-serif text-2xl font-semibold"><Gift class="h-6 w-6 text-brand-600" />{{ t('wishlist.title') }}</h1>
      <RouterLink to="/books/new?wishlist=1" class="btn-primary"><Plus class="h-4 w-4" />{{ t('wishlist.addBook') }}</RouterLink>
    </div>
    <p class="text-sm text-stone-600 dark:text-stone-400">{{ t('wishlist.intro') }}</p>

    <p v-if="error" class="card text-sm text-red-700">{{ error }}</p>
    <div v-else-if="loading" class="card text-sm text-stone-500">{{ t('common.loading') }}</div>

    <template v-else>
      <section class="card">
        <p v-if="!items.length" class="text-sm text-stone-500">{{ t('wishlist.empty') }}</p>
        <ul v-else class="divide-y divide-stone-200 dark:divide-stone-800">
          <li v-for="book in items" :key="book.id" class="flex flex-wrap items-center gap-3 py-2">
            <RouterLink :to="`/books/${book.id}`" class="flex min-w-0 flex-1 items-center gap-3">
              <BookCover :book="book" size="sm" />
              <span class="min-w-0">
                <span class="block truncate font-medium">{{ book.title }}</span>
                <span class="block truncate text-sm text-stone-500">{{ book.authors || t('common.unknownAuthor') }}</span>
              </span>
            </RouterLink>
            <div class="flex gap-1">
              <button class="btn-ghost !p-2" :title="t('wishlist.markBought')" :aria-label="t('wishlist.markBought')" :disabled="!store.online" @click="fulfil(book, 'owned')"><ShoppingBag class="h-4 w-4" /></button>
              <button class="btn-ghost !p-2" :title="t('wishlist.markBorrowed')" :aria-label="t('wishlist.markBorrowed')" :disabled="!store.online" @click="fulfil(book, 'library_loan')"><Library class="h-4 w-4" /></button>
              <button class="btn-ghost !p-2 text-red-600" :title="t('wishlist.remove')" :aria-label="t('wishlist.remove')" :disabled="!store.online" @click="remove(book)"><Trash2 class="h-4 w-4" /></button>
            </div>
          </li>
        </ul>
      </section>

      <section class="card space-y-3">
        <h2 class="font-semibold">{{ t('wishlist.sharing') }}</h2>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
          <label
            v-for="option in visibilities"
            :key="option.value"
            class="flex cursor-pointer gap-3 rounded-xl border-2 p-3 transition"
            :class="sharing.visibility === option.value ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-stone-200 dark:border-stone-800'"
          >
            <input type="radio" name="visibility" :value="option.value" :checked="sharing.visibility === option.value" class="sr-only" :disabled="!store.online" @change="share(option.value)" />
            <component :is="option.icon" class="h-5 w-5 shrink-0 text-brand-600" />
            <span>
              <span class="block text-sm font-medium">{{ option.label }}</span>
              <span class="block text-xs text-stone-500">{{ option.hint }}</span>
            </span>
          </label>
        </div>
        <div v-if="sharing.visibility === 'public' && publicLink" class="space-y-2">
          <div class="flex gap-2">
            <input :value="publicLink" readonly class="input font-mono text-xs" :aria-label="t('wishlist.link')" @focus="$event.target.select()" />
            <button class="btn-secondary" @click="copyLink"><Copy class="h-4 w-4" />{{ t('wishlist.copy') }}</button>
          </div>
          <button class="btn-ghost !px-1 text-xs" @click="share('public', true)"><RefreshCw class="h-3.5 w-3.5" />{{ t('wishlist.renew') }}</button>
        </div>
      </section>

      <section v-if="others.length" class="card space-y-3">
        <h2 class="font-semibold">{{ t('wishlist.others') }}</h2>
        <div v-for="list in others" :key="list.username">
          <h3 class="mb-1 text-sm font-medium text-stone-600 dark:text-stone-400">{{ t('wishlist.of', { name: list.username }) }}</h3>
          <ul class="divide-y divide-stone-200 dark:divide-stone-800">
            <li v-for="book in list.items" :key="book.id">
              <RouterLink :to="`/books/${book.id}`" class="flex items-center gap-3 py-2">
                <BookCover :book="book" size="sm" />
                <span class="min-w-0">
                  <span class="block truncate font-medium">{{ book.title }}</span>
                  <span class="block truncate text-sm text-stone-500">{{ book.authors || t('common.unknownAuthor') }}</span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </div>
      </section>
    </template>
  </div>
</template>
