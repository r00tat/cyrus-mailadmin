/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * The shell and its assets are served as static files out of `public/`
 * (architecture 11.1 and 11.3), next to the front controller. `public/` is not
 * this project's build directory, so `emptyOutDir` must stay off — wiping it
 * would take `index.php` and `.htaccess` with it. `scripts/clean-output.mjs`
 * removes exactly the generated files before every build instead.
 */
const projectRoot = fileURLToPath(new URL('.', import.meta.url));
const outDir = fileURLToPath(new URL('../public', import.meta.url));

/**
 * Where the application is mounted. `/` when the project directory *is* the
 * document root, `/mailadmin/` when it sits in a subdirectory of the web root
 * (U-25, deployment variant B). The same value the backend reads as
 * `MAILADMIN_BASE_PATH`, so a deployment configures one thing, not two (U-71).
 */
function basePath(): string {
  const raw = (process.env.MAILADMIN_BASE_PATH ?? '').trim();

  if (raw === '' || raw === '/') {
    return '/';
  }

  return `/${raw.replace(/^\/+|\/+$/g, '')}/`;
}

export default defineConfig({
  root: projectRoot,
  base: basePath(),
  plugins: [react()],
  build: {
    outDir,
    emptyOutDir: false,
    assetsDir: 'assets',
    // Content hash in every file name, so that `Cache-Control: immutable` in
    // public/.htaccess is safe (architecture 11.3).
    rollupOptions: {
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
        // Three chunks rather than one. Assets are served `immutable`
        // (architecture 11.3), so a change to application code should not make
        // a browser re-download MUI — which is by far the largest part and the
        // one that changes least.
        manualChunks: (id: string): string | undefined => {
          if (!id.includes('node_modules')) {
            return undefined;
          }

          if (/node_modules\/(@mui|@emotion)\//.test(id)) {
            return 'mui';
          }

          if (/node_modules\/(react|react-dom|react-router|scheduler)[/@]/.test(id)) {
            return 'react';
          }

          return 'vendor';
        },
      },
    },
    // Nothing is inlined as a `data:` URI. Vite inlines assets below 4 KiB by
    // default, which silently turned six of Roboto's smaller subsets into
    // `url(data:font/woff2;…)` — and `default-src 'self'` has no `data:` in it,
    // so `font-src` falls back to `'self'` and the browser refuses to load
    // them. The page stays readable through the fallback stack, which is
    // exactly why nobody would notice. Files instead of bytes; the CSP does not
    // move (U-35).
    assetsInlineLimit: 0,
    // U-17: the polyfill is injected as an *inline* <script>, which
    // `script-src 'self'` forbids. Turning it off is the reason the policy can
    // stay strict; scripts/check-build.mjs verifies the result.
    modulePreload: { polyfill: false },
    // No source maps in the shipped bundle. They are three times the size of
    // the code and would be served from public/ alongside it; the sources are
    // public anyway (AGPL, U-23), so what is saved is bandwidth and surprise,
    // not secrecy. `npm run dev` has full maps.
    sourcemap: false,
    target: 'es2022',
  },
  server: {
    // Only used by `npm run dev`. The API is same-origin in production
    // (architecture 11.1); in development the dev server proxies it so that the
    // client never needs to know a second origin and CORS stays out of the
    // picture.
    proxy: {
      '/api': {
        // The F5 test environment (`bin/test-env up`) publishes Apache here.
      // Override with MAILADMIN_DEV_API for anything else.
      target: process.env.MAILADMIN_DEV_API ?? 'http://127.0.0.1:18080',
        changeOrigin: false,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: false,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
  },
});
