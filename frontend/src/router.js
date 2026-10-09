import { createRouter, createWebHashHistory } from 'vue-router'
import { store } from './lib/store.js'
import { bootstrap } from './lib/session.js'

// Hash history: works on any shared host without rewrite rules.
export const router = createRouter({
  history: createWebHashHistory(),
  scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
  routes: [
    { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
    { path: '/invite/:token([0-9a-f]{64})', name: 'invite', component: () => import('./views/InviteView.vue'), props: true, meta: { public: true, bare: true } },
    { path: '/', name: 'home', component: () => import('./views/DashboardView.vue') },
    { path: '/books', name: 'books', component: () => import('./views/BooksView.vue') },
    { path: '/books/new', name: 'book-new', component: () => import('./views/BookFormView.vue') },
    { path: '/books/:id(\\d+)', name: 'book', component: () => import('./views/BookDetailView.vue'), props: true },
    { path: '/books/:id(\\d+)/edit', name: 'book-edit', component: () => import('./views/BookFormView.vue'), props: true },
    { path: '/scan', name: 'scan', component: () => import('./views/ScanView.vue') },
    { path: '/shelves', name: 'shelves', component: () => import('./views/ShelvesView.vue') },
    { path: '/shelves/new', name: 'shelf-new', component: () => import('./views/ShelfEditView.vue') },
    { path: '/shelves/:id(\\d+)', name: 'shelf', component: () => import('./views/ShelfView.vue'), props: true },
    { path: '/shelves/:id(\\d+)/edit', name: 'shelf-edit', component: () => import('./views/ShelfEditView.vue'), props: true },
    { path: '/stats', name: 'stats', component: () => import('./views/StatsView.vue') },
    { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (!store.ready) await bootstrap()
  if (!to.meta.public && !store.user) return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  if (to.name === 'login' && store.user) return { name: 'home' }
})
