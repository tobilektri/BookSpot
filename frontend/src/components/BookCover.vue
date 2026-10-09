<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { coverUrl, bookColor } from '../lib/format.js'

const props = defineProps({
  book: { type: Object, required: true },
  size: { type: String, default: 'md' }, // sm | md | lg
})

const img = ref(null)
const failed = ref(false)
const loaded = ref(false)
// Reset only when the image address really changes. Book objects are replaced on every
// catalogue refresh (cached copy first, then the server's); resetting on that left covers
// that had already loaded invisible, because the unchanged <img> fires no second load event.
const url = computed(() => coverUrl(props.book))
const src = computed(() => (failed.value ? null : url.value))
watch(url, () => {
  failed.value = false
  loaded.value = false
  nextTick(checkComplete)
})
onMounted(checkComplete)

/** An image served from cache can be complete before the load listener runs. */
function checkComplete() {
  const el = img.value
  if (el?.complete && el.naturalWidth > 0) loaded.value = true
}

const sizeClass = computed(() => ({ sm: 'w-10 h-14 text-[8px]', md: 'w-16 h-24 text-[10px]', lg: 'w-32 h-48 sm:w-40 sm:h-60 text-sm' })[props.size])
</script>

<template>
  <div class="relative shrink-0 overflow-hidden rounded-md shadow-sm ring-1 ring-black/5" :class="sizeClass">
    <!-- Generated cover is always rendered underneath; the real cover covers it once loaded. -->
    <div class="flex h-full w-full flex-col justify-between p-1.5 font-serif leading-tight text-white" :style="{ background: bookColor(book.title) }" aria-hidden="true">
      <span class="line-clamp-4 font-semibold">{{ book.title }}</span>
      <span class="line-clamp-2 opacity-80">{{ book.authors }}</span>
    </div>
    <img
      v-if="src"
      ref="img"
      :src="src"
      :alt="book.title"
      loading="lazy"
      decoding="async"
      class="absolute inset-0 h-full w-full object-cover transition-opacity duration-300"
      :class="loaded ? 'opacity-100' : 'opacity-0'"
      referrerpolicy="no-referrer"
      @load="loaded = true"
      @error="failed = true"
    />
  </div>
</template>
