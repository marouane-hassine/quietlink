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

/**
 * Lone surrogates, handled locally: String.prototype.isWellFormed/toWellFormed (ES2024) are
 * missing from supported browsers (Safari < 16.4, Firefox < 119, Chrome < 111). A manual scan
 * rather than a regular expression: lookbehind assertions are unsupported before Safari 16.4.
 */
function loneSurrogates(text: string): number[] {
  const positions: number[] = [];
  for (let i = 0; i < text.length; i++) {
    const unit = text.charCodeAt(i);
    if (unit >= 0xd800 && unit <= 0xdbff) {
      const next = i + 1 < text.length ? text.charCodeAt(i + 1) : 0;
      if (next >= 0xdc00 && next <= 0xdfff) i++;
      else positions.push(i);
    } else if (unit >= 0xdc00 && unit <= 0xdfff) {
      positions.push(i);
    }
  }
  return positions;
}

export function isWellFormedText(text: string): boolean {
  return loneSurrogates(text).length === 0;
}

/** Replaces each lone surrogate with U+FFFD, as String.prototype.toWellFormed() does. */
export function toWellFormedText(text: string): string {
  const positions = loneSurrogates(text);
  if (positions.length === 0) return text;
  let out = '';
  let from = 0;
  for (const position of positions) {
    out += text.slice(from, position) + '\uFFFD';
    from = position + 1;
  }
  return out + text.slice(from);
}

/** Normalises line endings and lone surrogates, then serialises (keys in example order). */
export function serialize(envelope: Omit<Envelope, 'v' | 'text'> & { text: string }): string {
  const text = toWellFormedText(envelope.text.replace(/\r\n?/g, '\n'));
  return JSON.stringify({ format: envelope.format, language: envelope.language, template: envelope.template, text, v: 1 });
}

export function byteLength(serialized: string): number {
  return new TextEncoder().encode(serialized).length;
}

/**
 * Members of the top-level JSON object, counted on the text: JSON.parse silently keeps the last
 * of duplicate members, which §8.2.1 forbids. Also returns the raw token of "v", which must be
 * the integer 1 as written (JSON.parse reads 1.0 and 1e0 as 1; the CLI rejects them). Only
 * called on text JSON.parse accepted.
 */
function topLevelMembers(json: string): { members: number; rawV: string | null } {
  let depth = 0;
  let inString = false;
  let members = 0;
  let stringStart = 0;
  let lastKey: string | null = null;
  let valueStart = -1;
  let rawV: string | null = null;
  const endValue = (end: number) => {
    if (valueStart >= 0 && lastKey === 'v') rawV = json.slice(valueStart, end).trim();
    valueStart = -1;
    lastKey = null;
  };
  for (let i = 0; i < json.length; i++) {
    const c = json[i];
    if (inString) {
      if (c === '\\') i++;
      else if (c === '"') {
        inString = false;
        // Decoded: "v" names "v" too. Valid JSON here, as JSON.parse accepted the text.
        if (depth === 1 && valueStart < 0) lastKey = JSON.parse(json.slice(stringStart - 1, i + 1)) as string;
      }
    } else if (c === '"') {
      inString = true;
      stringStart = i + 1;
      if (depth === 1 && members === 0) members = 1;
    } else if (c === '{' || c === '[') {
      depth++;
    } else if (c === '}' || c === ']') {
      if (depth === 1) endValue(i);
      depth--;
    } else if (c === ':' && depth === 1) {
      valueStart = i + 1;
    } else if (c === ',' && depth === 1) {
      endValue(i);
      members++;
    }
  }
  return { members, rawV };
}

/** Strict parsing: exactly the five members, once each, valid values, no unknown key. */
export function parseEnvelope(json: string): Envelope {
  let value: unknown;
  try {
    value = JSON.parse(json);
  } catch {
    throw new EnvelopeError('Invalid envelope');
  }
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new EnvelopeError('Invalid envelope');
  const record = value as Record<string, unknown>;
  const scan = topLevelMembers(json);
  if (Object.keys(record).sort().join() !== 'format,language,template,text,v' || scan.members !== 5 || scan.rawV !== '1') throw new EnvelopeError('Invalid envelope');
  const { format, language, template, text, v } = record;
  // Lone surrogates are refused as by the CLI: the text must be well-formed Unicode.
  if (v !== 1 || typeof text !== 'string' || !isWellFormedText(text) || typeof format !== 'string' || !['plain', 'markdown', 'code'].includes(format)
    || !(language === null || (typeof language === 'string' && /^[a-z0-9+#-]{1,32}$/.test(language)))
    || !(template === null || (typeof template === 'string' && /^[a-z-]{1,32}$/.test(template)))) {
    throw new EnvelopeError('Invalid envelope');
  }
  return { format: format as Format, language: language as string | null, template: template as string | null, text, v: 1 };
}
