// SPDX-License-Identifier: AGPL-3.0-or-later
// Bundles the load test for Node (npm run bench:load); output under var/, never shipped.
import { defineConfig } from 'vite';

export default defineConfig({
  logLevel: 'warn',
  publicDir: false,
  build: {
    ssr: 'tools/bench/load.ts',
    outDir: 'var/bench',
    emptyOutDir: true,
    target: 'node24',
    rollupOptions: { output: { entryFileNames: 'load.mjs' } },
  },
});
