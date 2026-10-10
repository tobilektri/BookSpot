<script setup>
import { ref, reactive, onMounted } from 'vue'
import { UserPlus, Trash2, KeyRound, Mail, VenetianMask, MailCheck, MailWarning, Copy } from '@lucide/vue'
import { api, ApiError } from '../lib/api.js'
import { t, errorMessage, fieldError, locale } from '../lib/i18n.js'
import { store, toast } from '../lib/store.js'
import { formatDate } from '../lib/format.js'
import BaseModal from './BaseModal.vue'
import { switchIdentity } from '../lib/session.js'

/** Admin user management: create accounts with role admin/user, change roles, reset passwords, delete. */
const users = ref([])
const showCreate = ref(false)
const form = reactive({ username: '', email: '', password: '', role: 'user', locale: locale.value, send_invite: true })
const inviteResult = ref(null) // {user, invite} after an invitation was created
const errors = ref({})
const busy = ref(false)
const passwordFor = ref(null)
const newPassword = ref('')

async function load() {
  users.value = (await api('v1/users')).users
}
onMounted(() => load().catch((e) => toast(errorMessage(e), 'error')))

function openCreate() {
  Object.assign(form, { username: '', email: '', password: '', role: 'user', locale: locale.value, send_invite: true })
  errors.value = {}
  showCreate.value = true
}

async function create() {
  busy.value = true
  errors.value = {}
  try {
    const { user, invite } = await api('v1/users', { method: 'POST', body: form })
    showCreate.value = false
    if (invite) inviteResult.value = { user, invite }
    else toast(t('users.created', { name: user.username }), 'success')
    await load()
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.fields
    toast(errorMessage(e), 'error')
  } finally {
    busy.value = false
  }
}

async function changeRole(user, role) {
  if (user.id === store.user.id && role !== 'admin' && !confirm(t('users.demoteSelf'))) {
    await load()
    return
  }
  try {
    await api(`v1/users/${user.id}`, { method: 'PUT', body: { role } })
    toast(t('common.saved'), 'success')
    if (user.id === store.user.id) store.user = { ...store.user, role }
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
  await load()
}

async function savePassword() {
  try {
    await api(`v1/users/${passwordFor.value.id}`, { method: 'PUT', body: { password: newPassword.value } })
    toast(t('users.passwordSet', { name: passwordFor.value.username }), 'success')
    passwordFor.value = null
    newPassword.value = ''
  } catch (e) {
    toast(e instanceof ApiError && e.fields.password ? fieldError(e.fields.password) : errorMessage(e), 'error')
  }
}

/** (Re)sends the invitation link – also usable to let someone choose a new password. */
async function invite(user) {
  try {
    const { invite } = await api(`v1/users/${user.id}/invite`, { method: 'POST' })
    inviteResult.value = { user, invite }
    await load()
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(inviteResult.value.invite.link)
    toast(t('cron.copied'), 'success')
  } catch {
    /* the link is selectable in the input field */
  }
}

async function impersonate(user) {
  if (!confirm(t('impersonate.confirm', { name: user.username }))) return
  try {
    await switchIdentity(() => api(`v1/users/${user.id}/impersonate`, { method: 'POST' }), '#/')
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}

async function remove(user) {
  if (!confirm(t('users.deleteConfirm', { name: user.username }))) return
  try {
    await api(`v1/users/${user.id}`, { method: 'DELETE' })
    toast(t('users.deleted', { name: user.username }), 'success')
    await load()
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
}
</script>

<template>
  <div class="space-y-3 text-sm">
    <div class="flex items-start justify-between gap-2">
      <p class="text-stone-600 dark:text-stone-400">{{ t('users.hint') }}</p>
      <button class="btn-primary shrink-0 !py-1.5" @click="openCreate"><UserPlus class="h-4 w-4" />{{ t('users.new') }}</button>
    </div>
    <ul class="divide-y divide-stone-100 dark:divide-stone-800">
      <li v-for="u in users" :key="u.id" class="flex flex-wrap items-center gap-2 py-2">
        <div class="min-w-0 flex-1">
          <div class="font-medium">{{ u.username }} <span v-if="u.id === store.user?.id" class="text-xs font-normal text-stone-500">({{ t('users.you') }})</span></div>
          <div class="truncate text-xs text-stone-500">{{ u.email }}<template v-if="u.last_login_at"> · {{ t('users.lastLogin', { date: formatDate(u.last_login_at) }) }}</template></div>
          <div v-if="u.invite_pending_until" class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">{{ t('users.invitePending', { date: formatDate(u.invite_pending_until) }) }}</div>
        </div>
        <select :value="u.role" class="input !w-auto !py-1 !text-sm" :aria-label="t('users.role')" @change="changeRole(u, $event.target.value)">
          <option value="admin">{{ t('users.role_admin') }}</option>
          <option value="user">{{ t('users.role_user') }}</option>
        </select>
        <button class="btn-ghost !p-2" :title="t('users.sendInvite')" :aria-label="t('users.sendInvite')" @click="invite(u)">
          <Mail class="h-4 w-4" />
        </button>
        <button v-if="u.id !== store.user?.id" class="btn-ghost !p-2" :title="t('impersonate.action', { name: u.username })" :aria-label="t('impersonate.action', { name: u.username })" @click="impersonate(u)">
          <VenetianMask class="h-4 w-4" />
        </button>
        <button class="btn-ghost !p-2" :title="t('users.setPassword')" :aria-label="t('users.setPassword')" @click="(passwordFor = u), (newPassword = '')">
          <KeyRound class="h-4 w-4" />
        </button>
        <button class="btn-ghost !p-2 text-red-600" :disabled="u.id === store.user?.id" :title="t('common.delete')" :aria-label="t('common.delete')" @click="remove(u)">
          <Trash2 class="h-4 w-4" />
        </button>
      </li>
    </ul>
    <p class="text-xs text-stone-500">{{ t('users.rolesHint') }}</p>

    <BaseModal v-if="showCreate" :title="t('users.new')" @close="showCreate = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="label" for="ua_username">{{ t('auth.username') }}</label>
          <input id="ua_username" v-model="form.username" class="input" required minlength="3" maxlength="50" autocomplete="off" />
          <p v-if="errors.username" class="field-error">{{ fieldError(errors.username) }}</p>
        </div>
        <div>
          <label class="label" for="ua_email">{{ t('auth.email') }}</label>
          <input id="ua_email" v-model="form.email" type="email" class="input" required autocomplete="off" />
          <p v-if="errors.email" class="field-error">{{ fieldError(errors.email) }}</p>
        </div>
        <label class="flex items-start gap-2 rounded-xl bg-brand-50 p-3 text-sm dark:bg-brand-900/20">
          <input v-model="form.send_invite" type="checkbox" class="mt-0.5 accent-brand-600" />
          <span>
            <span class="font-medium">{{ t('users.sendInviteOption') }}</span>
            <span class="block text-xs text-stone-600 dark:text-stone-400">{{ t('users.sendInviteHint') }}</span>
          </span>
        </label>
        <div v-if="!form.send_invite">
          <label class="label" for="ua_password">{{ t('users.initialPassword') }}</label>
          <input id="ua_password" v-model="form.password" type="password" class="input" required minlength="8" autocomplete="new-password" />
          <p class="mt-1 text-xs text-stone-500">{{ t('auth.passwordHint') }}</p>
          <p v-if="errors.password" class="field-error">{{ fieldError(errors.password) }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="ua_role">{{ t('users.role') }}</label>
            <select id="ua_role" v-model="form.role" class="input">
              <option value="user">{{ t('users.role_user') }}</option>
              <option value="admin">{{ t('users.role_admin') }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="ua_locale">{{ t('settings.language') }}</label>
            <select id="ua_locale" v-model="form.locale" class="input">
              <option value="de">Deutsch</option>
              <option value="en">English</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-stone-100 pt-4 dark:border-stone-800">
          <button type="button" class="btn-secondary" @click="showCreate = false">{{ t('common.cancel') }}</button>
          <button class="btn-primary" :disabled="busy">{{ t('users.create') }}</button>
        </div>
      </form>
    </BaseModal>

    <BaseModal v-if="inviteResult" :title="t('users.inviteTitle', { name: inviteResult.user.username })" @close="inviteResult = null">
      <div class="space-y-4 text-sm">
        <p v-if="inviteResult.invite.mail_sent" class="flex items-start gap-2 text-emerald-700 dark:text-emerald-400">
          <MailCheck class="mt-0.5 h-4 w-4 shrink-0" />{{ t('users.inviteSent', { email: inviteResult.invite.email }) }}
        </p>
        <p v-else class="flex items-start gap-2 text-amber-700 dark:text-amber-400">
          <MailWarning class="mt-0.5 h-4 w-4 shrink-0" />{{ inviteResult.invite.mail_available ? t('users.inviteMailFailed') : t('users.inviteMailUnavailable') }}
        </p>
        <div>
          <label class="label" for="invite_link">{{ t('users.inviteLink') }}</label>
          <div class="flex gap-2">
            <input id="invite_link" :value="inviteResult.invite.link" class="input font-mono !text-xs" readonly @focus="$event.target.select()" />
            <button class="btn-secondary shrink-0" :title="t('cron.copy')" @click="copyLink"><Copy class="h-4 w-4" /></button>
          </div>
          <p class="mt-1 text-xs text-stone-500">{{ t('users.inviteLinkHint', { date: formatDate(inviteResult.invite.expires_at) }) }}</p>
        </div>
        <div class="flex justify-end border-t border-stone-100 pt-4 dark:border-stone-800">
          <button class="btn-primary" @click="inviteResult = null">{{ t('common.close') }}</button>
        </div>
      </div>
    </BaseModal>

    <BaseModal v-if="passwordFor" :title="t('users.setPasswordFor', { name: passwordFor.username })" @close="passwordFor = null">
      <form class="space-y-4" @submit.prevent="savePassword">
        <input v-model="newPassword" type="password" class="input" required minlength="8" autocomplete="new-password" :aria-label="t('settings.newPassword')" />
        <p class="text-xs text-stone-500">{{ t('auth.passwordHint') }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="passwordFor = null">{{ t('common.cancel') }}</button>
          <button class="btn-primary">{{ t('common.save') }}</button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
