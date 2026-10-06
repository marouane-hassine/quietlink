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

/** A request still unanswered after this delay is abandoned as a network error, which offers
 * the usual Retry (same idempotency key or reservation) instead of waiting forever (§5.1). */
const REQUEST_TIMEOUT_MS = 30_000;
/** Slowest upload still accepted (bytes per millisecond, ~256 kbit/s): fetch only resolves once
 * the body is sent, so the delay grows with the body (a 1.4 MB creation gets ~74 s). */
const MIN_UPLOAD_BYTES_PER_MS = 32;

async function request<T>(method: string, path: string, body: string | null, headers: Record<string, string> = {}): Promise<Timed<T>> {
  const t0 = performance.now();
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS + (body === null ? 0 : body.length / MIN_UPLOAD_BYTES_PER_MS));
  let response: Response;
  try {
    response = await fetch(path, {
      signal: controller.signal,
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
  } finally {
    clearTimeout(timer);
  }
  const t1 = performance.now();
  if (response.status === 204) return { data: undefined as T, t0, t1 };
  if (!response.ok) {
    const retry = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
    const retryAfter = Number.isFinite(retry) ? retry : null;
    const kind: ApiErrorKind = ({ 404: 'unavailable', 409: 'reserved', 429: 'rate', 503: 'quota', 400: 'refused', 413: 'tooLarge', 422: 'refused' } as Record<number, ApiErrorKind>)[response.status] ?? 'server';
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
  /**
   * A fresh challenge with its lifetime in seconds (`expires_in`) and `issuedAfter`, the monotonic
   * instant the request left: the server cannot have issued it earlier (§6.3.1).
   */
  challenge: async (id: string, usage: 'open' | 'status'): Promise<{ challenge: string; expiresIn: number | null; issuedAfter: number }> => {
    const { data, t0 } = await request<{ challenge: string; expires_in?: number }>('POST', `${paste(id)}/challenge`, JSON.stringify({ usage }));
    return { challenge: data.challenge, expiresIn: typeof data.expires_in === 'number' && Number.isFinite(data.expires_in) ? data.expires_in : null, issuedAfter: t0 };
  },
  status: (id: string, body: object) => request<StatusResponse>('POST', `${paste(id)}/status`, JSON.stringify(body)),
  open: (id: string, body: object) => request<OpenResponse>('POST', `${paste(id)}/open`, JSON.stringify(body)),
  consume: (id: string, body: object) => request<{ consumed: boolean }>('POST', `${paste(id)}/consume`, JSON.stringify(body)),
  remove: (id: string, token: string) => request<undefined>('DELETE', paste(id), null, { 'X-Deletion-Token': token }),
};

/** Transient answers after which the exact same request may be sent again. */
const TRANSIENT: readonly ApiErrorKind[] = ['network', 'rate', 'server', 'quota'];
/** Longest Retry-After honoured before resending (seconds). */
const MAX_RETRY_AFTER = 10;

/**
 * Resends the exact same request (idempotent operations such as a consume with the same
 * signature, §12.3) after network errors and transient refusals (429, 5xx), waiting for
 * Retry-After when given (capped); a final answer (404, 409, 400...) stops the retries.
 */
export async function retrying<T>(call: () => Promise<T>, attempts = 3, delayMs = 500): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await call();
    } catch (error) {
      if (!(error instanceof ApiError) || !TRANSIENT.includes(error.kind) || attempt >= attempts) throw error;
      const wait = error.retryAfter !== null ? Math.min(error.retryAfter, MAX_RETRY_AFTER) * 1000 : delayMs * attempt;
      await new Promise((resolve) => setTimeout(resolve, wait));
    }
  }
}
