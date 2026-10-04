// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from 'vitest/config';

// Static, hashed assets served by the instance (no CDN, no inline scripts).
export default defineConfig({
  root: 'frontend',
  base: '/build/',
  publicDir: false,
  build: {
    outDir: '../public/build',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    target: 'es2022',
    modulePreload: { polyfill: false },
    assetsInlineLimit: 0,
    rollupOptions: {
      input: { app: 'frontend/src/main.ts' },
    },
  },
  worker: {
    format: 'es',
  },
  test: {
    root: '.',
    include: ['frontend/tests/**/*.test.ts'],
    environment: 'node',
  },
});
