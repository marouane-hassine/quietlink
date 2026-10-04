// SPDX-License-Identifier: AGPL-3.0-or-later
// Argon2id calibration on target devices (ADR-0008): runs the production worker with several
// parameter sets and prints durations to copy into the calibration report.

import { deriveInWorker } from '../../frontend/src/crypto/argon2-client';

const SETS: [number, number][] = [
  [65536, 3],
  [65536, 2],
  [47104, 2],
  [19456, 2],
];

const out = document.getElementById('out') as HTMLPreElement;
const button = document.getElementById('run') as HTMLButtonElement;

button.addEventListener('click', async () => {
  button.disabled = true;
  const lines = [`User agent: ${navigator.userAgent}`, `Logical cores: ${navigator.hardwareConcurrency ?? 'unknown'}`, ''];
  out.textContent = lines.join('\n');
  for (const [m, t] of SETS) {
    const runs: number[] = [];
    for (let i = 0; i < 3; i++) {
      const start = performance.now();
      await deriveInWorker('dummy calibration passphrase', crypto.getRandomValues(new Uint8Array(16)), m, t);
      runs.push(Math.round(performance.now() - start));
    }
    runs.sort((a, b) => a - b);
    lines.push(`m=${m} KiB t=${t} p=1: median ${runs[1]} ms (runs ${runs.join(', ')})`);
    out.textContent = lines.join('\n');
  }
  lines.push('', 'Target: default (m=65536, t=3) should stay under about 5 s on entry-level phones.');
  out.textContent = lines.join('\n');
  button.disabled = false;
});
