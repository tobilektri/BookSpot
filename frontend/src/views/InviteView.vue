<script setup>
import { ref, onMounted } from 'vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError, setLocale, appName } from '../lib/i18n.js'
import { store } from '../lib/store.js'
import { switchIdentity } from '../lib/session.js'
import { formatDate } from '../lib/format.js'
import logoUrl from '../assets/logo.svg'

/** Invitation link: the invited user chooses a password and is logged in. */
const props = defineProps({ token: { type: String, required: true } })
const info = ref(null)
const error = ref('')
const password = ref('')
const repeat = ref('')
const fieldErr = ref('')
const busy = ref(false)

onMounted(async () => {
  try {
    info.value = await api('auth.php', { query: { action: 'invite_info', token: props.token } })
    if (info.value.locale) setLocale(info.value.locale)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function submit() {
  fieldErr.value = ''
  if (password.value !== repeat.value) {
    fieldErr.value = t('invite.mismatch')
    return
  }
  busy.value = true
  try {
    await switchIdentity(() => api('auth.php', { method: 'POST', query: { action: 'accept_invite' }, body: { token: props.token, password: password.value } }), '#/')
  } catch (e) {
    busy.value = false
    if (e instanceof ApiError && e.fields.password) fieldErr.value = fieldError(e.fields.password)
    else error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center bg-gradient-to-b from-brand-50 to-stone-100 px-4 py-10 dark:from-stone-900 dark:to-stone-950">
    <div class="w-full max-w-sm">
      <div class="mb-6 text-center">
        <img :src="logoUrl" alt="" class="mx-auto h-16 w-16" />
        <h1 class="mt-3 font-serif text-3xl font-semibold text-brand-800 dark:text-brand-300">{{ store.appName || appName }}</h1>
      </div>
      <div v-if="error" class="card space-y-3 text-sm">
        <p class="text-red-700 dark:text-red-400" role="alert">{{ error }}</p>
        <p class="text-stone-600 dark:text-stone-400">{{ t('invite.askAdmin') }}</p>
        <RouterLink to="/login" class="btn-secondary w-full">{{ t('auth.login') }}</RouterLink>
      </div>
      <form v-else-if="info" class="card space-y-4" @submit.prevent="submit">
        <div>
          <h2 class="text-lg font-semibold">{{ t('invite.welcome', { name: info.username }) }}</h2>
          <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ t('invite.intro') }}</p>
        </div>
        <div>
          <label class="label" for="inv_password">{{ t('auth.password') }}</label>
          <input id="inv_password" v-model="password" type="password" class="input" autocomplete="new-password" required minlength="8" autofocus />
          <p class="mt-1 text-xs text-stone-500">{{ t('auth.passwordHint') }}</p>
        </div>
        <div>
          <label class="label" for="inv_repeat">{{ t('invite.repeat') }}</label>
          <input id="inv_repeat" v-model="repeat" type="password" class="input" autocomplete="new-password" required minlength="8" />
          <p v-if="fieldErr" class="field-error">{{ fieldErr }}</p>
        </div>
        <button class="btn-primary w-full" :disabled="busy">{{ t('invite.submit') }}</button>
        <p class="text-center text-xs text-stone-500">{{ t('invite.validUntil', { date: formatDate(info.expires_at) }) }} · {{ t('auth.username') }}: <strong>{{ info.username }}</strong></p>
      </form>
      <div v-else class="card text-sm text-stone-500">{{ t('common.loading') }}</div>
    </div>
  </div>
</template>
