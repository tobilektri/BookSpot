<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { LogOut, Download, Upload, Bell, User, Users, Info, Smartphone } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError, locale, setLocale, appName } from '../lib/i18n.js'
import { store, toast } from '../lib/store.js'
import { logout, rememberUser } from '../lib/session.js'
import ImportPanel from '../components/ImportPanel.vue'
import PushPanel from '../components/PushPanel.vue'
import EnrichButton from '../components/EnrichButton.vue'

const router = useRouter()
const route = useRoute()
const profile = reactive({ email: store.user?.email ?? '', locale: store.user?.locale ?? locale.value, current_password: '', new_password: '' })
const errors = ref({})
const users = ref([])
const installPrompt = ref(null)

onMounted(async () => {
  if (route.hash) nextTick(() => document.querySelector(route.hash)?.scrollIntoView({ behavior: 'smooth' }))
  if (store.user?.role === 'admin') {
    users.value = (await api('auth.php', { query: { action: 'users' } }).catch(() => ({ users: [] }))).users
  }
})

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  installPrompt.value = e
})

async function saveProfile() {
  errors.value = {}
  try {
    const { user } = await api('auth.php', { method: 'POST', query: { action: 'profile' }, body: profile })
    rememberUser(user)
    setLocale(user.locale)
    profile.current_password = ''
    profile.new_password = ''
    toast(t('common.saved'), 'success')
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.fields
    toast(errorMessage(e), 'error')
  }
}

async function doLogout() {
  await logout()
  router.replace('/login')
}

const exportUrl = new URL('api/export.php', document.baseURI).href
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6">
    <h1 class="font-serif text-2xl font-semibold">{{ t('settings.title') }}</h1>

    <section class="card space-y-3">
      <h2 class="flex items-center gap-2 font-semibold"><User class="h-5 w-5 text-brand-600" />{{ t('settings.profile') }} · {{ store.user?.username }}</h2>
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveProfile">
        <div>
          <label class="label" for="email">{{ t('auth.email') }}</label>
          <input id="email" v-model="profile.email" type="email" class="input" autocomplete="email" />
          <p v-if="errors.email" class="field-error">{{ fieldError(errors.email) }}</p>
        </div>
        <div>
          <label class="label" for="locale">{{ t('settings.language') }}</label>
          <select id="locale" v-model="profile.locale" class="input">
            <option value="de">Deutsch (SchmökerSpot)</option>
            <option value="en">English (BookSpot)</option>
          </select>
        </div>
        <div>
          <label class="label" for="current_password">{{ t('settings.currentPassword') }}</label>
          <input id="current_password" v-model="profile.current_password" type="password" class="input" autocomplete="current-password" />
          <p v-if="errors.current_password" class="field-error">{{ fieldError(errors.current_password) }}</p>
        </div>
        <div>
          <label class="label" for="new_password">{{ t('settings.newPassword') }}</label>
          <input id="new_password" v-model="profile.new_password" type="password" class="input" autocomplete="new-password" minlength="8" />
          <p v-if="errors.new_password" class="field-error">{{ fieldError(errors.new_password) }}</p>
        </div>
        <div class="sm:col-span-2 flex justify-end"><button class="btn-primary">{{ t('common.save') }}</button></div>
      </form>
    </section>

    <section id="import" class="card scroll-mt-20 space-y-3">
      <h2 class="flex items-center gap-2 font-semibold"><Upload class="h-5 w-5 text-brand-600" />{{ t('settings.importTitle') }}</h2>
      <ImportPanel />
    </section>

    <EnrichButton />

    <section class="card space-y-2">
      <h2 class="flex items-center gap-2 font-semibold"><Download class="h-5 w-5 text-brand-600" />{{ t('settings.exportTitle') }}</h2>
      <p class="text-sm text-stone-600 dark:text-stone-400">{{ t('settings.exportHint') }}</p>
      <a :href="exportUrl" class="btn-secondary" download>{{ t('settings.export') }}</a>
    </section>

    <section class="card space-y-2">
      <h2 class="flex items-center gap-2 font-semibold"><Bell class="h-5 w-5 text-brand-600" />{{ t('settings.pushTitle') }}</h2>
      <PushPanel />
    </section>

    <section v-if="users.length" class="card">
      <h2 class="mb-2 flex items-center gap-2 font-semibold"><Users class="h-5 w-5 text-brand-600" />{{ t('settings.users') }}</h2>
      <ul class="divide-y divide-stone-100 text-sm dark:divide-stone-800">
        <li v-for="u in users" :key="u.id" class="flex justify-between py-1.5">
          <span>{{ u.username }} <span class="text-stone-500">· {{ u.email }}</span></span>
          <span class="text-xs text-stone-500">{{ t(`settings.role_${u.role}`) }}</span>
        </li>
      </ul>
    </section>

    <section class="card space-y-3 text-sm">
      <h2 class="flex items-center gap-2 font-semibold"><Info class="h-5 w-5 text-brand-600" />{{ t('settings.about') }}</h2>
      <p>{{ store.appName || appName }} · {{ t('settings.version', { v: store.version }) }} · <a href="https://github.com/tobilektri/BookSpot" class="text-brand-700 underline" target="_blank" rel="noopener">{{ t('settings.sourceCode') }}</a></p>
      <div class="flex flex-wrap gap-2">
        <button v-if="installPrompt" class="btn-secondary" @click="installPrompt.prompt()"><Smartphone class="h-4 w-4" />{{ t('settings.installApp') }}</button>
        <button class="btn-secondary text-red-600" @click="doLogout"><LogOut class="h-4 w-4" />{{ t('auth.logout') }}</button>
      </div>
    </section>
  </div>
</template>
