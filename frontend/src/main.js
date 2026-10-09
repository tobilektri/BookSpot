import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.js'
import { store } from './lib/store.js'
import { setLocale, locale } from './lib/i18n.js'
import { initSync } from './lib/sync.js'
import './style.css'

setLocale(locale.value)

const app = createApp(App)
app.use(router)
app.mount('#app')

initSync()

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  navigator.serviceWorker
    .register('./sw.js', { scope: './' })
    .then((reg) => {
      const watch = (worker) => {
        worker?.addEventListener('statechange', () => {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) store.updateAvailable = reg
        })
      }
      if (reg.waiting && navigator.serviceWorker.controller) store.updateAvailable = reg
      reg.addEventListener('updatefound', () => watch(reg.installing))
      setInterval(() => reg.update().catch(() => {}), 60 * 60 * 1000)
    })
    .catch((e) => console.warn('Service worker registration failed', e))

  let reloading = false
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (reloading) return
    reloading = true
    window.location.reload()
  })
}
