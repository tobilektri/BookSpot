<script setup>
import { ref, computed, onMounted } from 'vue'
import { Plus, MapPin } from '@lucide/vue'
import { t, errorMessage } from '../lib/i18n.js'
import { shelves, loadShelves } from '../lib/shelves.js'
import { books, loadCatalog } from '../lib/catalog.js'
import ShelfGrid from '../components/ShelfGrid.vue'

const error = ref('')
const loading = ref(true)

onMounted(async () => {
  try {
    await Promise.all([loadShelves({ force: true }), loadCatalog().catch(() => {})])
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
})

const unplaced = computed(() => books.value.filter((b) => b.entry_type === 'owned' && !b.shelf_id).length)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="font-serif text-2xl font-semibold">{{ t('shelves.title') }}</h1>
      <RouterLink to="/shelves/new" class="btn-primary"><Plus class="h-4 w-4" />{{ t('shelves.new') }}</RouterLink>
    </div>

    <p v-if="error" class="card text-sm text-red-700">{{ error }}</p>
    <p v-else-if="!loading && !shelves.length" class="card text-sm text-stone-600 dark:text-stone-400">{{ t('shelves.empty') }}</p>

    <RouterLink v-if="unplaced" :to="{ name: 'books', query: { filter: 'unplaced' } }" class="card flex items-center gap-3 text-sm">
      <MapPin class="h-5 w-5 text-brand-600" />
      <span class="flex-1">{{ t('shelves.unplacedBooks') }}</span>
      <span class="rounded-full bg-brand-100 px-2 py-0.5 font-semibold text-brand-800 dark:bg-brand-900/40 dark:text-brand-300">{{ unplaced }}</span>
    </RouterLink>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      <RouterLink v-for="shelf in shelves" :key="shelf.id" :to="`/shelves/${shelf.id}`" class="card block transition hover:shadow-md">
        <div class="mb-3 flex items-start justify-between gap-2">
          <div class="min-w-0">
            <h2 class="truncate font-semibold">{{ shelf.name }}</h2>
            <p class="text-xs text-stone-500">
              <span v-if="shelf.room">{{ shelf.room }} · </span>{{ t('shelves.columns', { n: shelf.columns.length }) }} · {{ t('shelves.compartments', { n: shelf.compartment_count }) }}
            </p>
          </div>
          <span class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-sm font-medium dark:bg-stone-800">{{ t('common.books', { n: shelf.book_count }) }}</span>
        </div>
        <ShelfGrid :shelf="shelf" mode="preview" class="pointer-events-none" />
      </RouterLink>
    </div>
  </div>
</template>
