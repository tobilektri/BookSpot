<script setup>
import { ref, onMounted } from 'vue'
import { Gift } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage, appName } from '../lib/i18n.js'
import { store } from '../lib/store.js'
import BookCover from '../components/BookCover.vue'

// Wishlist shared by link: no sign-in, only title, author, publisher, year and cover.
const props = defineProps({ token: { type: String, required: true } })
const list = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    const data = await api(`v1/public/wishlists/${props.token}`)
    const coverBase = `api/v1/public/wishlists/${props.token}/covers`
    data.items = data.items.map((book) => ({
      ...book,
      cover_src: book.has_cover ? new URL(`${coverBase}/${book.id}?v=${book.cover_version}`, document.baseURI).href : null,
    }))
    list.value = data
  } catch (e) {
    error.value = e.status === 404 ? t('wishlist.publicGone') : errorMessage(e)
  }
})
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4 p-4">
    <h1 class="flex items-center gap-2 font-serif text-2xl font-semibold"><Gift class="h-6 w-6 text-brand-600" />{{ list ? t('wishlist.of', { name: list.username }) : t('wishlist.title') }}</h1>
    <p v-if="error" class="card text-sm text-stone-600">{{ error }}</p>
    <div v-else-if="!list" class="card text-sm text-stone-500">{{ t('common.loading') }}</div>
    <section v-else class="card">
      <p v-if="!list.items.length" class="text-sm text-stone-500">{{ t('wishlist.publicEmpty') }}</p>
      <ul v-else class="divide-y divide-stone-200 dark:divide-stone-800">
        <li v-for="book in list.items" :key="book.id" class="flex items-center gap-3 py-3">
          <BookCover :book="book" size="md" />
          <div class="min-w-0">
            <div class="font-medium">{{ book.title }}</div>
            <div class="text-sm text-stone-600 dark:text-stone-400">{{ book.authors || t('common.unknownAuthor') }}</div>
            <div class="mt-1 text-xs text-stone-500">
              {{ [book.publisher, book.published_year].filter(Boolean).join(' · ') }}<span v-if="book.isbn13"> · ISBN {{ book.isbn13 }}</span>
            </div>
          </div>
        </li>
      </ul>
    </section>
    <p class="text-center text-xs text-stone-500">{{ t('wishlist.publicFooter', { name: store.appName || appName }) }}</p>
  </div>
</template>
