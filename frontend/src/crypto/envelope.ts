// SPDX-License-Identifier: AGPL-3.0-or-later

/** Plaintext envelope of sp-proto/v1 §7.1. */

export type Format = 'plain' | 'markdown' | 'code';

export interface Envelope {
  format: Format;
  language: string | null;
  template: string | null;
  text: string;
  v: 1;
}

export class EnvelopeError extends Error {}

/** Normalises line endings and lone surrogates, then serialises (keys in example order). */
export function serialize(envelope: Omit<Envelope, 'v' | 'text'> & { text: string }): string {
  const text = envelope.text.replace(/\r\n?/g, '\n').toWellFormed();
  return JSON.stringify({ format: envelope.format, language: envelope.language, template: envelope.template, text, v: 1 });
}

export function byteLength(serialized: string): number {
  return new TextEncoder().encode(serialized).length;
}

/** Strict parsing: exactly the five members, valid values, no unknown key. */
export function parseEnvelope(json: string): Envelope {
  let value: unknown;
  try {
    value = JSON.parse(json);
  } catch {
    throw new EnvelopeError('Invalid envelope');
  }
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new EnvelopeError('Invalid envelope');
  const record = value as Record<string, unknown>;
  if (Object.keys(record).sort().join() !== 'format,language,template,text,v') throw new EnvelopeError('Invalid envelope');
  const { format, language, template, text, v } = record;
  if (v !== 1 || typeof text !== 'string' || !['plain', 'markdown', 'code'].includes(String(format))
    || !(language === null || (typeof language === 'string' && /^[a-z0-9+#-]{1,32}$/.test(language)))
    || !(template === null || (typeof template === 'string' && /^[a-z-]{1,32}$/.test(template)))) {
    throw new EnvelopeError('Invalid envelope');
  }
  return { format: format as Format, language: language as string | null, template: template as string | null, text, v: 1 };
}
