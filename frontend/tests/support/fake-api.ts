// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Test helpers: an in-memory stand-in for the JSON API v1 driven through a mocked `fetch`,
 * and small DOM/async utilities. No real secrets: every value is a dummy generated per test.
 */

import { vi } from 'vitest';
import { parse } from '../../src/crypto/aad';
import { decode, encode } from '../../src/crypto/base64url';
import { concat, randomBytes } from '../../src/crypto/bytes';
import { ID_ACCESS_PREFIX, ID_DELETE_PREFIX } from '../../src/crypto/constants';
import { fingerprint } from '../../src/crypto/primitives';

export interface RecordedRequest {
  method: string;
  path: string;
  body: string | null;
  headers: Record<string, string>;
}

export interface FakeResponse {
  status: number;
  json?: unknown;
}

export function response(status: number, json?: unknown): Response {
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: new Headers(),
    json: async () => json,
  } as unknown as Response;
}

/** Installs a mocked fetch; `handler` answers each request, every request is recorded. */
export function mockFetch(handler: (request: RecordedRequest) => Promise<Response> | Response) {
  const requests: RecordedRequest[] = [];
  const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const request: RecordedRequest = {
      method: init?.method ?? 'GET',
      path: String(input),
      body: typeof init?.body === 'string' ? init.body : null,
      headers: { ...(init?.headers as Record<string, string> | undefined) },
    };
    requests.push(request);
    return handler(request);
  });
  vi.stubGlobal('fetch', spy);
  return { spy, requests };
}

/** Server-side identifier of §5.4 computed from a creation body, as the real API does. */
export async function idForCreation(json: string): Promise<string> {
  const body = JSON.parse(json) as { aad: string; deletion_hash: string };
  const aad = parse(decode(body.aad));
  const id = concat(await fingerprint(ID_ACCESS_PREFIX, aad.accessPk), await fingerprint(ID_DELETE_PREFIX, decode(body.deletion_hash, 32)), randomBytes(8));
  return encode(id);
}

/** A 201 creation answer accepted by the client fingerprint checks. */
export async function creationResponse(json: string, expiresAt: string | null = '2026-10-04T12:00:00Z', serverTime = '2026-10-03T12:00:00Z'): Promise<Response> {
  return response(201, { id: await idForCreation(json), expires_at: expiresAt, server_time: serverTime });
}

export function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

/** Lets pending promises and zero-delay timers run (real timers only). */
export const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

// Captured at load: tests may fake timers or mock performance.now() afterwards.
const realImmediate = globalThis.setImmediate;
const realNow = () => Number(process.hrtime.bigint() / 1_000_000n);

/**
 * Polls `condition` for up to `timeoutMs` of real time. Waiting by elapsed time rather than by
 * event-loop turns keeps tests stable on slow CI runners, where Web Crypto work takes longer.
 */
export async function until(condition: () => boolean, timeoutMs = 5000): Promise<void> {
  const deadline = realNow() + timeoutMs;
  while (!condition()) {
    if (realNow() > deadline) throw new Error('Condition not reached');
    await new Promise((resolve) => realImmediate(resolve));
  }
}

/** jsdom leaves isSecureContext undefined; the application requires a secure context. */
export function setSecureContext(secure: boolean): void {
  Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true });
}

/**
 * Text rendered by the application (title, text nodes, accessible labels), excluding the values
 * of the fields the user typed into.
 */
export function renderedText(): string {
  const attributes = [...document.querySelectorAll('*')].flatMap((node) => ['aria-label', 'title', 'placeholder', 'alt'].map((name) => node.getAttribute(name) ?? ''));
  return [document.title, document.body.textContent ?? '', ...attributes].join('\n');
}

export const dummyChallenge = () => encode(randomBytes(82));
