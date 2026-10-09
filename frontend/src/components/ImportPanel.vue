<script setup>
import { ref, reactive } from 'vue'
import { Upload, CheckCircle2, TriangleAlert } from '@lucide/vue'
import { api } from '../lib/api.js'
import { t, errorMessage } from '../lib/i18n.js'
import { toast } from '../lib/store.js'
import { loadCatalog } from '../lib/catalog.js'
import { invalidateShelves } from '../lib/shelves.js'

const file = ref(null)
const options = reactive({ create_shelves: true, shelf_name: '', overwrite_personal: true, overwrite_location: false })
const busy = ref(false)
const result = ref(null)
const error = ref('')

async function run(dryRun) {
  if (!file.value) return
  busy.value = true
  error.value = ''
  result.value = null
  const form = new FormData()
  form.append('file', file.value)
  form.append('dry_run', dryRun ? '1' : '0')
  for (const [key, value] of Object.entries(options)) form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value)
  try {
    result.value = await api('import.php', { method: 'POST', form })
    if (!dryRun) {
      toast(t('settings.summary', result.value), 'success', 6000)
      invalidateShelves()
      loadCatalog({ force: true }).catch(() => {})
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const layoutText = (shelf) => shelf.columns.map((c) => `${c.label} (${c.rows})`).join(', ')
</script>

<template>
  <div class="space-y-3">
    <p class="text-sm text-stone-600 dark:text-stone-400">{{ t('settings.importHint') }}</p>
    <div>
      <label class="label" for="csv">{{ t('settings.file') }}</label>
      <input
        id="csv"
        type="file"
        accept=".csv,text/csv,text/plain"
        class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-2 file:font-medium file:text-brand-800"
        @change="(file = $event.target.files[0] ?? null), (result = null)"
      />
    </div>
    <label class="flex items-start gap-2 text-sm"><input v-model="options.create_shelves" type="checkbox" class="mt-1 accent-brand-600" />{{ t('settings.createShelves') }}</label>
    <div v-if="options.create_shelves" class="pl-6">
      <label class="label" for="shelf_name">{{ t('settings.shelfName') }}</label>
      <input id="shelf_name" v-model="options.shelf_name" class="input" maxlength="100" placeholder="Bücherregal" />
    </div>
    <label class="flex items-start gap-2 text-sm"><input v-model="options.overwrite_personal" type="checkbox" class="mt-1 accent-brand-600" />{{ t('settings.overwritePersonal') }}</label>
    <label class="flex items-start gap-2 text-sm"><input v-model="options.overwrite_location" type="checkbox" class="mt-1 accent-brand-600" />{{ t('settings.overwriteLocation') }}</label>

    <div class="flex flex-wrap gap-2">
      <button class="btn-secondary" :disabled="!file || busy" @click="run(true)">{{ t('settings.analyse') }}</button>
      <button class="btn-primary" :disabled="!file || busy" @click="run(false)"><Upload class="h-4 w-4" />{{ busy ? t('settings.importing') : t('settings.startImport') }}</button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ error }}</p>

    <div v-if="result" class="space-y-2 rounded-xl bg-stone-100 p-3 text-sm dark:bg-stone-800" role="status">
      <p class="flex items-start gap-2 font-medium">
        <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
        {{ t(result.dry_run ? 'settings.summaryDry' : 'settings.summary', { created: result.created, linked: result.linked + result.updated, skipped: result.skipped, errors: result.errors }) }}
      </p>
      <p>{{ t('settings.placed', { n: result.placed }) }}</p>
      <p v-for="shelf in result.shelves_created" :key="shelf.id">{{ t('settings.shelfCreated', { name: shelf.name, layout: layoutText(shelf) }) }}</p>
      <div v-if="Object.keys(result.unmatched_locations).length" class="rounded-lg bg-amber-50 p-2 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
        <p class="flex items-center gap-1 font-medium"><TriangleAlert class="h-4 w-4" />{{ t('settings.unmatched') }}</p>
        <p class="mt-1">
          <span v-for="(n, code) in result.unmatched_locations" :key="code" class="mr-1 mb-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs dark:bg-amber-900/50">{{ code }} ×{{ n }}</span>
        </p>
        <p class="mt-1 text-xs">{{ t('settings.unmatchedHint') }}</p>
      </div>
      <p class="text-xs text-stone-500">{{ t('settings.detected', { cols: result.detected_columns.join(', ') }) }}</p>
      <details v-if="result.messages.length">
        <summary class="cursor-pointer text-xs font-medium">{{ t('settings.messages') }} ({{ result.messages.length }})</summary>
        <ul class="mt-1 max-h-48 overflow-y-auto text-xs">
          <li v-for="(m, i) in result.messages" :key="i">#{{ m.row }} · {{ t(`import.${m.code}`) }}<span v-if="m.detail">: {{ m.detail }}</span></li>
        </ul>
      </details>
    </div>
  </div>
</template>
