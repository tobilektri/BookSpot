<script setup>
import { ref, computed, watch } from 'vue'
import { coverUrl, bookColor } from '../lib/format.js'

const props = defineProps({
  book: { type: Object, required: true },
  size: { type: String, default: 'md' }, // sm | md | lg
})

const failed = ref(false)
const loaded = ref(false)
const src = computed(() => (failed.value ? null : coverUrl(props.book, props.size === 'lg' ? 'L' : 'M')))
watch(
  () => [props.book?.id, props.book?.cover_version],
  () => {
    failed.value = false
    loaded.value = false
  },
)

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
