/// <reference lib="webworker" />
import { precacheAndRoute, cleanupOutdatedCaches, createHandlerBoundToURL } from 'workbox-precaching'
import { registerRoute, NavigationRoute } from 'workbox-routing'
import { NetworkFirst, CacheFirst } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'

// App shell: cache-first from the precache (manifest injected at build time).
precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()
registerRoute(new NavigationRoute(createHandlerBoundToURL('index.html'), { denylist: [/\/api\//] }))

// Marks API responses that were served from the cache because the network failed.
const offlineMarker = {
  cachedResponseWillBeUsed: async ({ cachedResponse }) => {
    if (!cachedResponse) return cachedResponse
    const headers = new Headers(cachedResponse.headers)
    headers.set('X-BookSpot-Offline', '1')
    return new Response(await cachedResponse.blob(), { status: cachedResponse.status, statusText: cachedResponse.statusText, headers })
  },
}

// API reads: network-first, falling back to the last cached response when offline.
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' &&
    url.origin === self.location.origin &&
    /\/api\/v1\/(books|shelves|stats|push|auth\/status|auth\/profile)(\/|$)/.test(url.pathname) &&
    !/\/cover$|\/batch(\/|$)/.test(url.pathname),
  new NetworkFirst({
    cacheName: 'bookspot-api',
    networkTimeoutSeconds: 8,
    plugins: [
      new CacheableResponsePlugin({ statuses: [200] }),
      new ExpirationPlugin({ maxEntries: 400, maxAgeSeconds: 60 * 60 * 24 * 60 }),
      offlineMarker,
    ],
  }),
)

// Book covers are stored on our own server (api/v1/books/{id}/cover) and cached here for offline use.
// External images are deliberately not intercepted: a fetch() from the service worker is
// subject to connect-src 'self' and would fail.
registerRoute(
  ({ url }) => url.origin === self.location.origin && /\/api\/v1\/books\/\d+\/cover$/.test(url.pathname),
  new CacheFirst({
    cacheName: 'bookspot-covers-v1',
    plugins: [
      new CacheableResponsePlugin({ statuses: [200] }),
      new ExpirationPlugin({ maxEntries: 3000, maxAgeSeconds: 60 * 60 * 24 * 365, purgeOnQuotaError: true }),
    ],
  }),
)

// Old cover caches: hotlinked covers, then covers under the pre-v1 URL api/cover.php.
self.addEventListener('activate', (event) =>
  event.waitUntil(Promise.all(['bookspot-covers', 'bookspot-covers-local'].map((name) => caches.delete(name)))),
)

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting()
  if (event.data?.type === 'CLEAR_API_CACHE') event.waitUntil(caches.delete('bookspot-api'))
})

// Background Sync: ask an open page to replay the Dexie offline queue.
self.addEventListener('sync', (event) => {
  if (event.tag !== 'bookspot-sync') return
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      clients.forEach((client) => client.postMessage({ type: 'bookspot-sync' }))
    }),
  )
})

// Web Push (VAPID) – payload: {title, body, url, tag}
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data?.json() ?? {}
  } catch {
    data = { title: 'SchmökerSpot', body: event.data?.text() ?? '' }
  }
  const scope = self.registration.scope
  event.waitUntil(
    self.registration.showNotification(data.title || 'SchmökerSpot', {
      body: data.body || '',
      icon: new URL('app-icons/icon-192.png', scope).href,
      badge: new URL('app-icons/badge-96.png', scope).href,
      tag: data.tag,
      renotify: Boolean(data.tag),
      data: { url: new URL(data.url || './', scope).href },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = event.notification.data?.url || self.registration.scope
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      for (const client of windows) {
        if (client.url.startsWith(self.registration.scope)) {
          await client.focus()
          return client.navigate(target).catch(() => null)
        }
      }
      return self.clients.openWindow(target)
    })(),
  )
})
