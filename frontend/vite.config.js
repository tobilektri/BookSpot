import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import { rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const outDir = fileURLToPath(new URL('../public', import.meta.url))

// Removes stale hashed bundles from ../public/assets while keeping public/api/*.php.
const cleanAssets = () => ({
  name: 'bookspot-clean-assets',
  apply: 'build',
  buildStart() {
    rmSync(`${outDir}/assets`, { recursive: true, force: true })
  },
})

export default defineConfig({
  // Relative base: the app works from any sub-directory on shared hosting.
  base: './',
  publicDir: 'static',
  plugins: [
    cleanAssets(),
    vue(),
    tailwindcss(),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.js',
      injectRegister: false,
      registerType: 'autoUpdate',
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,svg,png,webmanifest,woff2}'],
        globIgnores: ['api/**'],
        // Classic script output (sw.js): some shared hosters serve .mjs with a wrong MIME type.
        rollupFormat: 'iife',
      },
      manifest: {
        id: './',
        name: 'SchmökerSpot – BookSpot',
        short_name: 'SchmökerSpot',
        description: 'Bücher, Regale, Ausleihen und Lesestatistiken verwalten.',
        lang: 'de',
        start_url: './',
        scope: './',
        display: 'standalone',
        orientation: 'portrait',
        background_color: '#fafaf9',
        theme_color: '#b45309',
        categories: ['books', 'productivity', 'lifestyle'],
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
        shortcuts: [
          { name: 'Buch scannen', short_name: 'Scannen', url: './#/scan', icons: [{ src: 'icons/icon-192.png', sizes: '192x192' }] },
          { name: 'Statistik', short_name: 'Statistik', url: './#/stats', icons: [{ src: 'icons/icon-192.png', sizes: '192x192' }] },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
  build: {
    outDir,
    emptyOutDir: false,
    chunkSizeWarningLimit: 900,
  },
  server: {
    // `npm run dev` proxies the PHP API (php -S localhost:8000 -t public, or docker on :8080).
    proxy: {
      '/api': { target: process.env.BOOKSPOT_API ?? 'http://localhost:8000', changeOrigin: true },
    },
  },
})
