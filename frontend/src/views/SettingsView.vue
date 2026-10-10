<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { LogOut, Download, Upload, Bell, User, Users, Info, Smartphone, Clock, GraduationCap, Library } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError, locale, setLocale, appName } from '../lib/i18n.js'
import { store, toast } from '../lib/store.js'
import { logout, rememberUser } from '../lib/session.js'
import ImportPanel from '../components/ImportPanel.vue'
import PushPanel from '../components/PushPanel.vue'
import EnrichButton from '../components/EnrichButton.vue'
import UserAdmin from '../components/UserAdmin.vue'
import CronInfo from '../components/CronInfo.vue'
import InstallGuide from '../components/InstallGuide.vue'
import { install, installMethod, promptInstall } from '../lib/engagement.js'

const router = useRouter()
const route = useRoute()
const profile = reactive({ email: store.user?.email ?? '', locale: store.user?.locale ?? locale.value, current_password: '', new_password: '' })
const errors = ref({})
const library = reactive({ library_name: store.user?.library_name ?? '', library_loan_days: store.user?.library_loan_days ?? '' })
const libraryErrors = ref({})

onMounted(async () => {
  if (route.hash) nextTick(() => document.querySelector(route.hash)?.scrollIntoView({ behavior: 'smooth' }))
})


async function saveProfile() {
  errors.value = {}
  try {
    const { user } = await api('v1/auth/profile', { method: 'PUT', body: profile })
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

async function saveLibrary() {
  libraryErrors.value = {}
  try {
    const { user } = await api('v1/auth/profile', { method: 'PUT', body: { ...library } })
    rememberUser(user)
    library.library_name = user.library_name ?? ''
    library.library_loan_days = user.library_loan_days ?? ''
    toast(t('common.saved'), 'success')
  } catch (e) {
    if (e instanceof ApiError) libraryErrors.value = e.fields
    toast(errorMessage(e), 'error')
  }
}

async function doLogout() {
  await logout()
  router.replace('/login')
}

const exportUrl = new URL('api/v1/export', document.baseURI).href
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6">
    <h1 class="font-serif text-2xl font-semibold">{{ t('settings.title') }}</h1>

    <section class="card space-y-3">
      <h2 class="flex items-center gap-2 font-semibold"><User class="h-5 w-5 text-brand-600" />{{ t('settings.profile') }} · {{ store.user?.username }} <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-normal dark:bg-stone-800">{{ t(`users.role_${store.user?.role}`) }}</span></h2>
      <form class="grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="saveProfile">
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

    <section id="library" class="card scroll-mt-20 space-y-3">
      <h2 class="flex items-center gap-2 font-semibold"><Library class="h-5 w-5 text-brand-600" />{{ t('settings.library') }}</h2>
      <p class="text-sm text-stone-600 dark:text-stone-400">{{ t('settings.libraryHint') }}</p>
      <form class="grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="saveLibrary">
        <div>
          <label class="label" for="library_name">{{ t('settings.libraryName') }}</label>
          <input id="library_name" v-model="library.library_name" class="input" maxlength="150" placeholder="Stadtbibliothek" />
        </div>
        <div>
          <label class="label" for="library_loan_days">{{ t('settings.loanDays') }}</label>
          <div class="flex items-center gap-2">
            <input id="library_loan_days" v-model="library.library_loan_days" type="number" min="1" max="365" inputmode="numeric" class="input" placeholder="28" />
            <span class="text-sm text-stone-500">{{ t('settings.days') }}</span>
          </div>
          <p v-if="libraryErrors.library_loan_days" class="field-error">{{ fieldError(libraryErrors.library_loan_days) }}</p>
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

    <section id="install" class="card scroll-mt-20 space-y-3">
      <h2 class="flex items-center gap-2 font-semibold"><Smartphone class="h-5 w-5 text-brand-600" />{{ t('settings.installApp') }}</h2>
      <p v-if="install.installed" class="text-sm text-emerald-700 dark:text-emerald-400">{{ t('install.alreadyInstalled') }}</p>
      <template v-else-if="installMethod() === 'prompt'">
        <p class="text-sm text-stone-600 dark:text-stone-400">{{ t('engage.installText') }}</p>
        <button class="btn-primary" @click="promptInstall"><Smartphone class="h-4 w-4" />{{ t('engage.install') }}</button>
      </template>
      <InstallGuide v-else-if="installMethod()" />
      <p v-else class="text-sm text-stone-600 dark:text-stone-400">{{ t('install.unsupported') }}</p>
    </section>

    <section id="push" class="card scroll-mt-20 space-y-2">
      <h2 class="flex items-center gap-2 font-semibold"><Bell class="h-5 w-5 text-brand-600" />{{ t('settings.pushTitle') }}</h2>
      <PushPanel />
    </section>

    <template v-if="store.user?.role === 'admin'">
      <section id="users" class="card scroll-mt-20">
        <h2 class="mb-2 flex items-center gap-2 font-semibold"><Users class="h-5 w-5 text-brand-600" />{{ t('users.title') }}</h2>
        <UserAdmin />
      </section>
      <section id="cron" class="card scroll-mt-20">
        <h2 class="mb-2 flex items-center gap-2 font-semibold"><Clock class="h-5 w-5 text-brand-600" />{{ t('cron.title') }}</h2>
        <CronInfo />
      </section>
    </template>

    <section class="card space-y-3 text-sm">
      <h2 class="flex items-center gap-2 font-semibold"><Info class="h-5 w-5 text-brand-600" />{{ t('settings.about') }}</h2>
      <p>{{ store.appName || appName }} · {{ t('settings.version', { v: store.version }) }} · <a href="https://github.com/tobilektri/BookSpot" class="text-brand-700 underline" target="_blank" rel="noopener">{{ t('settings.sourceCode') }}</a></p>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" @click="store.showOnboarding = true"><GraduationCap class="h-4 w-4" />{{ t('onboarding.restart') }}</button>
        <button class="btn-secondary text-red-600" @click="doLogout"><LogOut class="h-4 w-4" />{{ t('auth.logout') }}</button>
      </div>
    </section>
  </div>
</template>
