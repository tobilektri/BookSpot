<script setup>
import { ref, onMounted, onUnmounted, watch, shallowRef } from 'vue'
import {
  Chart,
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  DoughnutController,
  LinearScale,
  Tooltip,
  Legend,
} from 'chart.js'

Chart.register(ArcElement, BarController, BarElement, CategoryScale, DoughnutController, LinearScale, Tooltip, Legend)

/** Thin wrapper: re-creates the chart whenever the config factory output changes. */
const props = defineProps({ config: { type: Function, required: true }, label: { type: String, default: '' } })
const canvas = ref(null)
const chart = shallowRef(null)

function render() {
  chart.value?.destroy()
  chart.value = new Chart(canvas.value, props.config())
}

const media = window.matchMedia('(prefers-color-scheme: dark)')
onMounted(() => {
  render()
  media.addEventListener('change', render)
})
onUnmounted(() => {
  media.removeEventListener('change', render)
  chart.value?.destroy()
})
watch(() => props.config(), render, { deep: true })
</script>

<template>
  <div class="relative h-full w-full">
    <canvas ref="canvas" role="img" :aria-label="label"></canvas>
  </div>
</template>
