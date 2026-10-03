// SPDX-License-Identifier: AGPL-3.0-or-later

/** JSON API v1 client (§10). Errors are mapped to generic, translatable kinds. */

export type ApiErrorKind = 'network' | 'unavailable' | 'reserved' | 'rate' | 'quota' | 'refused' | 'tooLarge' | 'server';

export class ApiError extends Error {
  constructor(public readonly kind: ApiErrorKind, public readonly retryAfter: number | null = null) {
    super(kind);
  }
}

export interface Timed<T> {
  data: T;
  t0: number;
  t1: number;
}

async function request<T>(method: string, path: string, body: string | null, headers: Record<string, string> = {}): Promise<Timed<T>> {
  const t0 = performance.now();
  let response: Response;
  try {
    response = await fetch(path, {
      method,
      body,
      headers: body === null ? headers : { 'Content-Type': 'application/json', ...headers },
      credentials: 'omit',
      cache: 'no-store',
      redirect: 'error',
      referrerPolicy: 'no-referrer',
    });
  } catch {
    throw new ApiError('network');
  }
  const t1 = performance.now();
  if (response.status === 204) return { data: undefined as T, t0, t1 };
  if (!response.ok) {
    const retry = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
    const retryAfter = Number.isFinite(retry) ? retry : null;
    const kind: ApiErrorKind = ({ 404: 'unavailable', 409: 'reserved', 429: 'rate', 503: 'quota', 400: 'refused', 413: 'tooLarge', 422: 'server' } as Record<number, ApiErrorKind>)[response.status] ?? 'server';
    throw new ApiError(kind, retryAfter);
  }
  try {
    return { data: (await response.json()) as T, t0, t1 };
  } catch {
    throw new ApiError('server');
  }
}

export interface CreateResponse {
  id: string;
  expires_at: string | null;
  server_time: string;
}

export interface StatusResponse {
  aad: string;
  expires_at: string | null;
  server_time: string;
  read_once: boolean;
  state?: 'available' | 'reserved';
  retry_after?: number | null;
  unconfirmed_opens?: number;
}

export interface OpenResponse extends StatusResponse {
  nonce: string;
  ciphertext: string;
  consume_challenge?: string;
}

const paste = (id: string) => `/api/v1/pastes/${encodeURIComponent(id)}`;

export const api = {
  /** Same Idempotency-Key and byte-identical body on every retry (§10). */
  create: (json: string, idempotencyKey: string) => request<CreateResponse>('POST', '/api/v1/pastes', json, { 'Idempotency-Key': idempotencyKey }),
  challenge: async (id: string, usage: 'open' | 'status') => (await request<{ challenge: string }>('POST', `${paste(id)}/challenge`, JSON.stringify({ usage }))).data.challenge,
  status: (id: string, body: object) => request<StatusResponse>('POST', `${paste(id)}/status`, JSON.stringify(body)),
  open: (id: string, body: object) => request<OpenResponse>('POST', `${paste(id)}/open`, JSON.stringify(body)),
  consume: (id: string, body: object) => request<{ consumed: boolean }>('POST', `${paste(id)}/consume`, JSON.stringify(body)),
  remove: (id: string, token: string) => request<undefined>('DELETE', paste(id), null, { 'X-Deletion-Token': token }),
};
