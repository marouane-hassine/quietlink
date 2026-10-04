#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
// Bundle analysis after each build (§13): the first screen must not load Markdown rendering,
// syntax highlighting, the QR generator or Argon2id; the initial JavaScript stays within budget.

import { readFileSync, statSync } from 'node:fs';

const BUDGET_BYTES = 80 * 1024;
const LAZY = ['markdown', 'highlight', 'qrcode', 'argon2.worker', 'content-view'];
const manifest = JSON.parse(readFileSync('public/build/.vite/manifest.json', 'utf8'));

const seen = new Set();
const walk = (key) => {
  if (seen.has(key)) return;
  seen.add(key);
  for (const dependency of manifest[key]?.imports ?? []) walk(dependency);
};
walk('src/main.ts');

let total = 0;
const files = [];
for (const key of seen) {
  const file = manifest[key].file;
  const size = statSync(`public/build/${file}`).size;
  total += size;
  files.push(`${file} ${size}`);
}
console.log(`Initial JavaScript: ${total} bytes (budget ${BUDGET_BYTES})\n  ${files.join('\n  ')}`);
const leaked = files.filter((line) => LAZY.some((name) => line.includes(name)));
if (leaked.length > 0) {
  console.error(`Lazy modules loaded on the first screen: ${leaked.join(', ')}`);
  process.exit(1);
}
if (total > BUDGET_BYTES) {
  console.error('Initial JavaScript exceeds the budget.');
  process.exit(1);
}
