<script setup>
import { ref, computed, watch } from 'vue'
import { t, errorMessage, locale } from '../lib/i18n.js'
import { api } from '../lib/api.js'
import { formatNumber, formatPrice } from '../lib/format.js'
import { chartTheme, statusSlot, baseOptions } from '../lib/chartTheme.js'
import ChartCanvas from '../components/ChartCanvas.vue'

const stats = ref(null)
const error = ref('')
const year = ref(new Date().getFullYear())
const metric = ref('books') // books | pages
const showTable = ref(false)

async function load() {
  error.value = ''
  try {
    stats.value = await api('stats.php', { query: { year: year.value } })
  } catch (e) {
    error.value = errorMessage(e)
  }
}
watch(year, load, { immediate: true })

const statusOrder = ['read', 'unread', 'reading', 'abandoned']
const statusTotal = computed(() => statusOrder.reduce((s, k) => s + (stats.value?.status[k] ?? 0), 0))
const statusRows = computed(() =>
  statusOrder
    .map((key) => ({ key, value: stats.value?.status[key] ?? 0 }))
    .filter((r) => r.value > 0 || r.key !== 'abandoned')
    .map((r) => ({ ...r, percent: statusTotal.value ? Math.round((r.value / statusTotal.value) * 100) : 0, color: chartTheme().series[statusSlot[r.key]] })),
)

const donutConfig = () => {
  const theme = chartTheme()
  const rows = statusRows.value
  return {
    type: 'doughnut',
    data: {
      labels: rows.map((r) => t(`status.${r.key}`)),
      datasets: [{ data: rows.map((r) => r.value), backgroundColor: rows.map((r) => theme.series[statusSlot[r.key]]), borderColor: theme.surface, borderWidth: 2, hoverOffset: 4 }],
    },
    options: { ...baseOptions(theme), scales: {}, cutout: '68%' },
  }
}

// Genres: top 10 + "Others", stacked read vs. open (one axis, two series).
const genreRows = computed(() => {
  const rows = (stats.value?.genres ?? []).map((g) => ({ ...g, label: g.genre ?? t('stats.noGenre') }))
  const named = rows.filter((g) => g.genre)
  const top = named.slice(0, 10)
  const rest = named.slice(10)
  if (rest.length) {
    const sum = (k) => rest.reduce((s, g) => s + g[k], 0)
    top.push({ label: `${t('stats.others')} (${rest.length})`, total: sum('total'), read: sum('read'), reading: sum('reading'), pages_read: sum('pages_read') })
  }
  return top
})
// Books without genre would dwarf every bar, so they are reported as a note instead.
const noGenreCount = computed(() => (stats.value?.genres ?? []).find((g) => !g.genre)?.total ?? 0)

const genreConfig = () => {
  const theme = chartTheme()
  const rows = genreRows.value
  const opts = baseOptions(theme)
  return {
    type: 'bar',
    data: {
      labels: rows.map((g) => g.label),
      datasets: [
        { label: t('stats.genreRead'), data: rows.map((g) => g.read), backgroundColor: theme.series[0], borderRadius: 4, borderSkipped: false, maxBarThickness: 18 },
        { label: t('stats.genreOpen'), data: rows.map((g) => g.total - g.read), backgroundColor: theme.rest, borderRadius: 4, borderSkipped: false, maxBarThickness: 18 },
      ],
    },
    options: {
      ...opts,
      indexAxis: 'y',
      plugins: { ...opts.plugins, legend: { display: true, position: 'top', align: 'start', labels: { color: theme.text, boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 3 } } },
      scales: {
        x: { ...opts.scales.y, stacked: true },
        y: { ...opts.scales.x, stacked: true, ticks: { color: theme.text, autoSkip: false, callback: (v, i) => (rows[i].label.length > 22 ? rows[i].label.slice(0, 21) + '…' : rows[i].label) } },
      },
    },
  }
}

const monthLabels = computed(() =>
  (stats.value?.months ?? []).map((m) => new Date(`${m.month}-15`).toLocaleDateString(locale.value === 'en' ? 'en-GB' : 'de-DE', { month: 'short' })),
)

function barConfig(labels, values, name) {
  const theme = chartTheme()
  return {
    type: 'bar',
    data: { labels, datasets: [{ label: name, data: values, backgroundColor: theme.series[0], borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 28 }] },
    options: baseOptions(theme),
  }
}

const metricName = computed(() => (metric.value === 'books' ? t('stats.booksAxis') : t('stats.pagesAxis')))
const monthConfig = () => barConfig(monthLabels.value, (stats.value?.months ?? []).map((m) => m[metric.value]), metricName.value)
const yearConfig = () => barConfig((stats.value?.years ?? []).map((y) => String(y.year)), (stats.value?.years ?? []).map((y) => y[metric.value]), metricName.value)
const monthHasData = computed(() => (stats.value?.months ?? []).some((m) => m.books > 0))
const maxAuthor = computed(() => Math.max(1, ...(stats.value?.top_authors ?? []).map((a) => a.total)))
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-serif text-2xl font-semibold">{{ t('stats.title') }}</h1>
      <div class="flex items-center gap-2">
        <div class="flex rounded-xl bg-stone-200 p-1 text-sm dark:bg-stone-800" role="group">
          <button v-for="m in ['books', 'pages']" :key="m" class="rounded-lg px-3 py-1" :class="metric === m ? 'bg-white shadow dark:bg-stone-950' : ''" @click="metric = m">
            {{ m === 'books' ? t('stats.booksAxis') : t('stats.pagesAxis') }}
          </button>
        </div>
        <select v-model.number="year" class="input !w-auto !py-1.5" :aria-label="t('stats.year')">
          <option v-for="y in stats?.available_years ?? [year]" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
    </div>

    <p v-if="error" class="card text-sm text-red-700">{{ error }}</p>
    <div v-else-if="!stats" class="card text-sm text-stone-500">{{ t('common.loading') }}</div>

    <template v-else>
      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card">
          <div class="text-xs text-stone-500">{{ t('stats.booksTotal') }}</div>
          <div class="mt-1 text-3xl font-semibold">{{ formatNumber(stats.totals.books) }}</div>
          <div class="text-xs text-stone-500">{{ t('stats.readShare', { p: statusTotal ? Math.round((stats.status.read / statusTotal) * 100) : 0 }) }}</div>
        </div>
        <div class="card">
          <div class="text-xs text-stone-500">{{ t('stats.pagesRead') }}</div>
          <div class="mt-1 text-3xl font-semibold">{{ formatNumber(stats.totals.pages_read) }}</div>
          <div class="text-xs text-stone-500">{{ t('stats.pagesTotal') }}: {{ formatNumber(stats.totals.pages) }}</div>
        </div>
        <div class="card">
          <div class="text-xs text-stone-500">{{ t('stats.thisYear') }} ({{ year }})</div>
          <div class="mt-1 text-3xl font-semibold">{{ formatNumber(stats.pace.books_this_year) }}</div>
          <div class="text-xs text-stone-500">{{ formatNumber(stats.pace.pages_this_year) }} {{ t('stats.pagesAxis') }} · {{ formatNumber(stats.pace.pages_per_day_this_year, 1) }} {{ t('stats.pagesPerDay') }}</div>
        </div>
        <div class="card">
          <div class="text-xs text-stone-500">{{ t('stats.avgRating') }}</div>
          <div class="mt-1 text-3xl font-semibold">{{ stats.totals.avg_rating ? formatNumber(stats.totals.avg_rating, 1) + ' ★' : '–' }}</div>
          <div class="text-xs text-stone-500">{{ t('stats.value') }}: {{ formatPrice(stats.totals.value) }}</div>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
        <section class="card">
          <h2 class="section-title">{{ t('stats.statusChart') }}</h2>
          <div class="flex flex-wrap items-center gap-4">
            <div class="relative h-32 w-32 shrink-0 sm:h-40 sm:w-40">
              <ChartCanvas :config="donutConfig" :label="t('stats.statusChart')" />
              <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-semibold">{{ formatNumber(statusTotal) }}</span>
                <span class="text-xs text-stone-500">{{ t('dashboard.statBooks') }}</span>
              </div>
            </div>
            <ul class="space-y-2 text-sm">
              <li v-for="row in statusRows" :key="row.key" class="flex items-center gap-2">
                <span class="h-3 w-3 shrink-0 rounded-sm" :style="{ background: row.color }"></span>
                <span class="min-w-18">{{ t(`status.${row.key}`) }}</span>
                <span class="font-semibold tabular-nums">{{ formatNumber(row.value) }}</span>
                <span class="whitespace-nowrap text-stone-500 tabular-nums">({{ row.percent }} %)</span>
              </li>
            </ul>
          </div>
        </section>

        <section class="card">
          <h2 class="section-title">{{ t('stats.perMonth', { year }) }} · {{ metricName }}</h2>
          <div class="h-56"><ChartCanvas :config="monthConfig" :label="t('stats.perMonth', { year })" /></div>
          <p v-if="!monthHasData" class="mt-2 text-xs text-stone-500">{{ t('stats.noData') }}</p>
          <p v-if="stats.totals.read_without_date" class="mt-2 text-xs text-stone-500">{{ t('stats.withoutDate', { n: stats.totals.read_without_date }) }}</p>
        </section>
      </div>

      <section class="card">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-lg font-semibold">{{ t('stats.genres') }}</h2>
          <button class="btn-ghost !py-1 text-xs" @click="showTable = !showTable">{{ showTable ? t('stats.chart') : t('stats.table') }}</button>
        </div>
        <div v-if="!showTable" :style="{ height: `${Math.max(160, genreRows.length * 30 + 50)}px` }">
          <ChartCanvas :config="genreConfig" :label="t('stats.genres')" />
        </div>
        <p v-if="noGenreCount && !showTable" class="mt-2 text-xs text-stone-500">{{ t('stats.noGenreNote', { n: noGenreCount }) }}</p>
        <table v-else-if="showTable" class="w-full text-sm">
          <thead class="text-left text-xs text-stone-500">
            <tr><th class="py-1">{{ t('book.genre') }}</th><th class="text-right">{{ t('dashboard.statBooks') }}</th><th class="text-right">{{ t('stats.genreRead') }}</th><th class="text-right">{{ t('stats.pagesRead') }}</th></tr>
          </thead>
          <tbody class="divide-y divide-stone-100 tabular-nums dark:divide-stone-800">
            <tr v-for="g in genreRows" :key="g.label"><td class="py-1">{{ g.label }}</td><td class="text-right">{{ g.total }}</td><td class="text-right">{{ g.read }}</td><td class="text-right">{{ formatNumber(g.pages_read) }}</td></tr>
            <tr v-if="noGenreCount" class="text-stone-500"><td class="py-1">{{ t('stats.noGenre') }}</td><td class="text-right">{{ noGenreCount }}</td><td></td><td></td></tr>
          </tbody>
        </table>
      </section>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section class="card">
          <h2 class="section-title">{{ t('stats.perYear') }} · {{ metricName }}</h2>
          <div v-if="stats.years.length" class="h-56"><ChartCanvas :config="yearConfig" :label="t('stats.perYear')" /></div>
          <p v-else class="text-sm text-stone-500">{{ t('stats.noData') }}</p>
        </section>

        <section class="card space-y-4">
          <div>
            <h2 class="section-title">{{ t('stats.pace') }}</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-xs text-stone-500">{{ t('stats.avgDays') }}</dt><dd class="text-xl font-semibold">{{ stats.pace.avg_days_per_book ?? '–' }}</dd></div>
              <div><dt class="text-xs text-stone-500">{{ t('stats.pagesPerDay') }}</dt><dd class="text-xl font-semibold">{{ stats.pace.pages_per_day ?? '–' }}</dd></div>
            </dl>
          </div>
          <div>
            <h2 class="section-title">{{ t('stats.topAuthors') }}</h2>
            <ul class="space-y-1.5 text-sm">
              <li v-for="a in stats.top_authors" :key="a.authors">
                <div class="flex justify-between gap-2"><span class="truncate">{{ a.authors }}</span><span class="shrink-0 tabular-nums text-stone-500">{{ a.read }}/{{ a.total }}</span></div>
                <div class="mt-0.5 flex h-1.5 gap-0.5 overflow-hidden rounded-full">
                  <div class="rounded-full" :style="{ width: `${(a.read / maxAuthor) * 100}%`, background: chartTheme().series[0] }"></div>
                  <div class="rounded-full" :style="{ width: `${((a.total - a.read) / maxAuthor) * 100}%`, background: chartTheme().rest }"></div>
                </div>
              </li>
            </ul>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>
