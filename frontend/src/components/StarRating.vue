<script setup>
import { Star } from '@lucide/vue'
const props = defineProps({ modelValue: { type: Number, default: null }, readonly: Boolean, size: { type: String, default: 'h-6 w-6' } })
const emit = defineEmits(['update:modelValue'])
function set(value) {
  if (props.readonly) return
  emit('update:modelValue', props.modelValue === value ? null : value)
}
</script>

<template>
  <div class="flex items-center gap-0.5" role="radiogroup">
    <button
      v-for="n in 5"
      :key="n"
      type="button"
      class="text-amber-500 disabled:cursor-default"
      :disabled="readonly"
      role="radio"
      :aria-checked="modelValue === n"
      :aria-label="`${n} / 5`"
      @click="set(n)"
    >
      <Star :class="[size, (modelValue ?? 0) >= n ? 'fill-current' : 'fill-transparent opacity-40']" />
    </button>
  </div>
</template>
