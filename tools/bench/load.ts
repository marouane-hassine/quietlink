// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Phase 3 load test (§13, EXG-PERF-009, EXG-PERF-010): create and open throughput and API p95
// latency against a disposable instance. Payloads are real sp-proto/v1 pastes built with the
// frontend crypto modules from dummy text; nothing secret is generated or printed.
//
// Usage: npm run bench:load -- [--url http://localhost:8095] [--creates 2000] [--opens 4000]
//                              [--concurrency 16] [--size 4096]
// The instance must allow that many requests from one address (tools/bench/server.sh lifts them).

import { encode } from '../../frontend/src/crypto/base64url';
import { serialize } from '../../frontend/src/crypto/envelope';
import { accessSeed, prepare, prove, type PreparedPaste } from '../../frontend/src/crypto/protocol';

const args = new Map<string, string>();
for (let i = 2; i < process.argv.length; i += 2) args.set(process.argv[i]!.replace(/^--/, ''), process.argv[i + 1] ?? '');
const base = (args.get('url') ?? 'http://localhost:8095').replace(/\/$/, '');
const creates = Number(args.get('creates') ?? 2000);
const opens = Number(args.get('opens') ?? 4000);
const concurrency = Number(args.get('concurrency') ?? 16);
const size = Number(args.get('size') ?? 4096);

const latencies = new Map<string, number[]>();
const failures = new Map<string, number>();

async function call(name: string, method: string, path: string, body: string | null, headers: Record<string, string> = {}): Promise<unknown> {
  const t0 = performance.now();
  const response = await fetch(base + path, {
    method,
    body,
    headers: body === null ? headers : { 'Content-Type': 'application/json', ...headers },
  });
  const text = await response.text();
  (latencies.get(name) ?? latencies.set(name, []).get(name)!).push(performance.now() - t0);
  if (!response.ok) {
    const key = `${name} ${response.status}`;
    failures.set(key, (failures.get(key) ?? 0) + 1);
    throw new Error(key);
  }
  return text === '' ? null : JSON.parse(text);
}

/** Runs `total` jobs with `concurrency` workers; returns the elapsed seconds. */
async function run(total: number, job: (index: number) => Promise<void>): Promise<number> {
  let next = 0;
  const t0 = performance.now();
  await Promise.all(Array.from({ length: concurrency }, async () => {
    while (next < total) {
      const index = next++;
      await job(index).catch(() => undefined);
    }
  }));
  return (performance.now() - t0) / 1000;
}

const percentile = (values: number[], p: number): number => {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)] ?? 0;
};

const text = 'dummy load test line\n'.repeat(Math.ceil(size / 21)).slice(0, size);
const envelope = serialize({ format: 'plain', language: null, template: null, text });
const derive = () => Promise.reject(new Error('no passphrase in the load test'));

console.log(`preparing ${creates} pastes of ${size} bytes…`);
const prepared: PreparedPaste[] = [];
for (let i = 0; i < creates; i++) {
  prepared.push(await prepare({ envelope, expiration: '1h', readOnce: false, passphrase: null, kdf: { m: 65536, t: 3 }, derive }));
}

const created: { id: string; seed: Uint8Array; pk: string }[] = [];
const createSeconds = await run(creates, async (i) => {
  const paste = prepared[i]!;
  const response = await call('create', 'POST', '/api/v1/pastes', paste.json, { 'Idempotency-Key': paste.idempotencyKey }) as { id: string };
  created.push({ id: response.id, seed: await accessSeed(paste.urlKey), pk: encode(paste.accessPk) });
});
if (created.length === 0) {
  console.error('no paste was created; check the URL, the rate limits and the quotas');
  process.exit(1);
}

const openSeconds = await run(opens, async (i) => {
  const paste = created[i % created.length]!;
  const path = `/api/v1/pastes/${paste.id}`;
  const { challenge } = await call('challenge', 'POST', `${path}/challenge`, JSON.stringify({ usage: 'open' })) as { challenge: string };
  await call('open', 'POST', `${path}/open`, JSON.stringify({ challenge, access_pk: paste.pk, signature: await prove(paste.seed, challenge) }));
});

const createRate = created.length / createSeconds;
const openRate = (latencies.get('open')?.length ?? 0) / openSeconds;
const p95 = Math.max(...[...latencies.values()].map((values) => percentile(values, 95)));
const check = (ok: boolean) => (ok ? 'PASS' : 'FAIL');

console.log(`creates: ${created.length} in ${createSeconds.toFixed(1)} s = ${createRate.toFixed(0)}/s (target ≥ 50/s): ${check(createRate >= 50)}`);
console.log(`opens (challenge + open): ${opens} in ${openSeconds.toFixed(1)} s = ${openRate.toFixed(0)}/s (target ≥ 200/s): ${check(openRate >= 200)}`);
for (const [name, values] of latencies) {
  console.log(`  ${name.padEnd(9)} n=${values.length} p50=${percentile(values, 50).toFixed(1)} ms p95=${percentile(values, 95).toFixed(1)} ms`);
}
console.log(`p95 latency (worst endpoint, including the local network): ${p95.toFixed(1)} ms (target < 200 ms): ${check(p95 < 200)}`);
for (const [key, count] of failures) console.log(`  failed: ${key} × ${count}`);

process.exit(createRate >= 50 && openRate >= 200 && p95 < 200 && failures.size === 0 ? 0 : 1);
