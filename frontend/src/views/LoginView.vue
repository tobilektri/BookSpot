<script setup>
import { ref, reactive, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { api, ApiError } from '../lib/api.js'
import { store } from '../lib/store.js'
import { t, errorMessage, fieldError, locale, setLocale, appName } from '../lib/i18n.js'
import { rememberUser } from '../lib/session.js'
import { invalidateCatalog } from '../lib/catalog.js'
import logoUrl from '../assets/logo.svg'

const router = useRouter()
const route = useRoute()
const mode = ref(store.needsSetup ? 'register' : 'login')
const form = reactive({ login: '', username: '', email: '', password: '' })
const errors = ref({})
const error = ref('')
const busy = ref(false)
const canRegister = computed(() => store.registrationOpen || store.needsSetup)

async function submit() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const result =
      mode.value === 'login'
        ? await api('auth.php', { method: 'POST', query: { action: 'login' }, body: { login: form.login, password: form.password } })
        : await api('auth.php', {
            method: 'POST',
            query: { action: 'register' },
            body: { username: form.username, email: form.email, password: form.password, locale: locale.value },
          })
    rememberUser(result.user)
    store.needsSetup = false
    invalidateCatalog()
    router.replace(typeof route.query.next === 'string' ? route.query.next : '/')
  } catch (e) {
    error.value = errorMessage(e)
    if (e instanceof ApiError) errors.value = e.fields
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center bg-gradient-to-b from-brand-50 to-stone-100 px-4 py-10 dark:from-stone-900 dark:to-stone-950">
    <div class="w-full max-w-sm">
      <div class="mb-6 text-center">
        <img :src="logoUrl" alt="" class="mx-auto h-16 w-16" />
        <h1 class="mt-3 font-serif text-3xl font-semibold text-brand-800 dark:text-brand-300">{{ store.appName || appName }}</h1>
        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ t('app.tagline') }}</p>
      </div>

      <form class="card space-y-4" @submit.prevent="submit">
        <div v-if="store.needsSetup" class="rounded-xl bg-brand-50 p-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-200">
          <strong>{{ t('auth.setupTitle') }}</strong>
          <p class="mt-1 text-xs">{{ t('auth.setupHint') }}</p>
        </div>

        <template v-if="mode === 'login'">
          <div>
            <label class="label" for="login">{{ t('auth.loginField') }}</label>
            <input id="login" v-model="form.login" class="input" autocomplete="username" required autofocus />
          </div>
        </template>
        <template v-else>
          <div>
            <label class="label" for="username">{{ t('auth.username') }}</label>
            <input id="username" v-model="form.username" class="input" autocomplete="username" required minlength="3" maxlength="50" />
            <p v-if="errors.username" class="field-error">{{ fieldError(errors.username) }}</p>
          </div>
          <div>
            <label class="label" for="email">{{ t('auth.email') }}</label>
            <input id="email" v-model="form.email" type="email" class="input" autocomplete="email" required />
            <p v-if="errors.email" class="field-error">{{ fieldError(errors.email) }}</p>
          </div>
        </template>
        <div>
          <label class="label" for="password">{{ t('auth.password') }}</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            class="input"
            :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
            required
            :minlength="mode === 'login' ? 1 : 8"
          />
          <p v-if="mode === 'register'" class="mt-1 text-xs text-stone-500">{{ t('auth.passwordHint') }}</p>
          <p v-if="errors.password" class="field-error">{{ fieldError(errors.password) }}</p>
        </div>

        <p v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300" role="alert">{{ error }}</p>

        <button class="btn-primary w-full" :disabled="busy">{{ mode === 'login' ? t('auth.login') : t('auth.register') }}</button>

        <p v-if="canRegister && !store.needsSetup" class="text-center text-sm text-stone-600 dark:text-stone-400">
          {{ mode === 'login' ? t('auth.noAccount') : t('auth.haveAccount') }}
          <button type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-400" @click="mode = mode === 'login' ? 'register' : 'login'">
            {{ mode === 'login' ? t('auth.register') : t('auth.login') }}
          </button>
        </p>
      </form>

      <div class="mt-4 flex justify-center gap-2 text-sm">
        <button v-for="l in ['de', 'en']" :key="l" class="rounded px-2 py-1" :class="locale === l ? 'font-semibold text-brand-700' : 'text-stone-500'" @click="setLocale(l)">
          {{ l.toUpperCase() }}
        </button>
      </div>
    </div>
  </div>
</template>
