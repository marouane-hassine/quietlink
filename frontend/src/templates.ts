// SPDX-License-Identifier: AGPL-3.0-or-later

/** Markdown templates (§6.1.1), filled entirely in the browser. Labels come from the catalogs. */

// Every shipped catalogue, to recognise field labels written in any language (this module is
// only loaded with the creation and reading pages, not on the first screen).
const CATALOGS = Object.values(import.meta.glob<Record<string, unknown>>('../../translations/*.json', { eager: true, import: 'default' }));
import { t } from './i18n';

interface Section {
  title: string;
  fields: string[];
}

export const TEMPLATES: Record<string, Section[]> = {
  credentials: [
    { title: 'service', fields: ['name', 'url', 'environment'] },
    { title: 'identity', fields: ['username', 'password', 'relatedToken'] },
    { title: 'security', fields: ['expiresOn', 'rotation', 'contact'] },
    { title: 'notes', fields: [] },
  ],
  'api-token': [
    { title: 'service', fields: ['name', 'environment'] },
    { title: 'token', fields: ['identifier', 'token', 'expiresOn'] },
    { title: 'notes', fields: [] },
  ],
  wifi: [
    { title: 'network', fields: ['name', 'ssid', 'security', 'password', 'hidden'] },
    { title: 'location', fields: ['location'] },
    { title: 'notes', fields: [] },
  ],
  'ssh-key': [
    { title: 'server', fields: ['host', 'user', 'port', 'fingerprint'] },
    { title: 'key', fields: ['keyOrPath'] },
    { title: 'usage', fields: ['command'] },
  ],
  database: [
    { title: 'connection', fields: ['engine', 'host', 'port', 'database', 'user', 'password'] },
    { title: 'parameters', fields: ['parameters'] },
  ],
  'env-vars': [{ title: 'variables', fields: ['name', 'value', 'environment', 'comment'] }],
  'temporary-access': [
    { title: 'access', fields: ['resource', 'beneficiary', 'permissions', 'start', 'end'] },
    { title: 'revocation', fields: ['procedure'] },
  ],
  incident: [
    { title: 'context', fields: [] },
    { title: 'impact', fields: [] },
    { title: 'commands', fields: [] },
    { title: 'logs', fields: [] },
    { title: 'actions', fields: [] },
  ],
};

export function renderTemplate(id: string): string {
  const sections = TEMPLATES[id];
  if (!sections) return '';
  const lines = [`# ${t(`template.${id}`)}`, ''];
  for (const section of sections) {
    lines.push(`## ${t(`tpl.section.${section.title}`)}`);
    for (const field of section.fields) lines.push(t('tpl.fieldLine', { label: t(`tpl.field.${field}`) }));
    lines.push('');
  }
  return lines.join('\n');
}


/** Fields whose value is masked by default in the form editor and the reading view. */
export const SENSITIVE_FIELDS = ['password', 'token', 'relatedToken', 'keyOrPath', 'value'];

const SENSITIVE_LABELS = new Set(
  CATALOGS.flatMap((catalog) => SENSITIVE_FIELDS.map((field) => String((catalog as Record<string, unknown>)[`tpl.field.${field}`] ?? '').toLowerCase())),
);

export function isSensitiveLabel(label: string): boolean {
  return SENSITIVE_LABELS.has(label.trim().toLowerCase());
}

export interface TemplateField {
  label: string;
  value: string;
  separator: string;
}

export interface TemplateSection {
  title: string;
  fields: TemplateField[];
  notes: string[];
}

export interface ParsedTemplate {
  title: string;
  sections: TemplateSection[];
}

const FIELD_LINE = /^- (.+?)( ?:)(?: (.*))?$/;

/** True for a "- label: value" line, which the parser reads as a field before any note. */
export function isFieldLine(line: string): boolean {
  return FIELD_LINE.test(line);
}

/** Parses text written from a template (# title, ## sections, "- label: value" lines). */
export function parseTemplateText(text: string): ParsedTemplate | null {
  const lines = text.replace(/\r\n?/g, '\n').split('\n');
  const first = lines.findIndex((line) => line.trim() !== '');
  const heading = first >= 0 ? /^# (.+)$/.exec(lines[first] ?? '') : null;
  if (!heading?.[1]) return null;
  const parsed: ParsedTemplate = { title: heading[1].trim(), sections: [] };
  let current: TemplateSection | null = null;
  for (const line of lines.slice(first + 1)) {
    // A title is required: "##" followed by blanks only would vanish when written back.
    const section = /^## (.*\S.*)$/.exec(line);
    if (section?.[1]) {
      current = { title: section[1].trim(), fields: [], notes: [] };
      parsed.sections.push(current);
      continue;
    }
    if (line.trim() === '') {
      // Blank lines inside notes are kept; the ones closing a section are trimmed below.
      if (current && current.notes.length > 0) current.notes.push('');
      continue;
    }
    if (!current) return null;
    const field = FIELD_LINE.exec(line);
    if (field?.[1] && field[2] && current.notes.length === 0) current.fields.push({ label: field[1], separator: field[2], value: field[3] ?? '' });
    else current.notes.push(line);
  }
  for (const section of parsed.sections) {
    while (section.notes.at(-1) === '') section.notes.pop();
  }
  if (parsed.sections.length === 0) return null;
  // Form and field views only for texts they can write back unchanged (§6.1.1, §6.2): notes
  // before fields, blank lines or "## " inside code blocks would be moved or lost.
  return serializeTemplate(parsed).trimEnd() === text.replace(/\r\n?/g, '\n').trimEnd() ? parsed : null;
}

export function serializeTemplate(template: ParsedTemplate): string {
  const lines = [`# ${template.title}`, ''];
  for (const section of template.sections) {
    lines.push(`## ${section.title}`);
    for (const field of section.fields) lines.push(`- ${field.label}${field.separator}${field.value === '' ? '' : ` ${field.value}`}`);
    lines.push(...section.notes, '');
  }
  return lines.join('\n');
}

/** A field value counts only when it holds more than spaces. */
export const fieldIsFilled = (field: TemplateField): boolean => field.value.trim() !== '';

const hasNotes = (section: TemplateSection): boolean => section.notes.some((line) => line.trim() !== '');

/** A section still worth showing: at least one filled field or one non-blank notes line. */
export function sectionHasContent(section: TemplateSection): boolean {
  return section.fields.some(fieldIsFilled) || hasNotes(section);
}

/** Fields shown and stored: all of them when the section has notes (a value is often typed on
 * the line below its label, which reads as a note), else only the filled ones. */
export function visibleFields(section: TemplateSection): TemplateField[] {
  return hasNotes(section) ? section.fields : section.fields.filter(fieldIsFilled);
}

/** Template parse tolerant of spaces typed after an empty field's colon ("- URL: "). */
function parseLenient(text: string): ParsedTemplate | null {
  return parseTemplateText(text.replace(/\r\n?/g, '\n').replace(/^(- .+? ?:)[ \t]+$/gm, '$1'));
}

/**
 * Template text as encrypted at creation: empty fields and sections left without content are
 * dropped (§6.2). Text that is not a template is returned unchanged.
 */
export function compactTemplateText(text: string): string {
  const parsed = parseLenient(text);
  if (!parsed) return text;
  const sections = parsed.sections.filter(sectionHasContent).map((section) => ({ ...section, fields: visibleFields(section) }));
  return serializeTemplate({ title: parsed.title, sections });
}

/** True for a template text with nothing filled in (it would be stored as its bare title). */
export function templateIsBlank(text: string): boolean {
  const parsed = parseLenient(text);
  return parsed !== null && !parsed.sections.some(sectionHasContent);
}

/** Field identifier (tpl.field.<id>) of a label written in any catalog language, or null. */
export function fieldIdForLabel(label: string): string | null {
  const wanted = label.trim().toLowerCase();
  for (const catalog of CATALOGS) {
    for (const [key, value] of Object.entries(catalog)) {
      if (key.startsWith('tpl.field.') && typeof value === 'string' && value.toLowerCase() === wanted) return key.slice('tpl.field.'.length);
    }
  }
  return null;
}

/** "Yes" in the shipped languages, for the hidden-network field. */
const HIDDEN_YES = new Set(['yes', 'y', 'true', '1', 'oui', 'sí', 'si', 'sì', 'نعم']);

/** Escapes a value for the WIFI: QR payload (backslash before \ ; , : and "). */
export function escapeWifi(value: string): string {
  return value.replace(/([\\;,:"])/g, '\\$1');
}

/** WIFI:T:<type>;S:<ssid>;P:<password>;[H:true;]; from a filled Wi-Fi template, or null when incomplete. */
export function wifiPayload(template: ParsedTemplate): string | null {
  const values = new Map<string, string>();
  for (const section of template.sections) {
    for (const field of section.fields) {
      const id = fieldIdForLabel(field.label);
      if (id && field.value !== '' && !values.has(id)) values.set(id, field.value);
    }
  }
  const ssid = values.get('ssid') ?? '';
  if (ssid === '') return null;
  const security = (values.get('security') ?? '').toLowerCase();
  const password = values.get('password') ?? '';
  // Open only without a password: one that was entered is never dropped from the code. WEP
  // only when named without WPA ("WPA2/WEP" networks accept WPA). SAE only for WPA3 alone:
  // transition networks (WPA2/WPA3) keep WPA, which every phone understands.
  const type = password === ''
    ? 'nopass'
    : /\bwep\b/.test(security) && !/wpa/.test(security)
      ? 'WEP'
      : /wpa3/.test(security) && !/wpa(?!3)/.test(security) ? 'SAE' : 'WPA';
  // First word only, NFC and lower case: "Oui (masqué)", "Yes, hidden" or a decomposed "sí" count.
  const firstWord = /^[\p{L}\p{N}]+/u.exec((values.get('hidden') ?? '').normalize('NFC').trim().toLowerCase())?.[0] ?? '';
  const hidden = HIDDEN_YES.has(firstWord) ? 'H:true;' : '';
  return `WIFI:T:${type};S:${escapeWifi(ssid)};${type === 'nopass' ? '' : `P:${escapeWifi(password)};`}${hidden};`;
}

/**
 * Escaping of notes lines (§6.1.1), exactly reversible: a line starting with "## " (any line)
 * or a first non-empty line written like a field ("- label: value") gets one more leading
 * backslash, including when backslashes already precede the pattern, so that a backslash
 * typed there is kept. Markdown shows "\## " and "\- " as typed.
 */
const SECTION_ESCAPE = /^\\*## /;
const FIELD_ESCAPE = /^\\*- /;

const isEscapedField = (line: string, escaped: RegExp): boolean => escaped.test(line) && isFieldLine(line.replace(/^\\+/, ''));

/** Notes lines as written in the text, from the lines typed (blank edges already trimmed). */
export function escapeNotes(typed: string[]): string[] {
  const first = typed.findIndex((line) => line.trim() !== '');
  return typed.map((line, index) => (SECTION_ESCAPE.test(line) || (index === first && isEscapedField(line, FIELD_ESCAPE)) ? `\\${line}` : line));
}

/** Notes lines as typed: removes exactly the backslash escapeNotes() could have added. */
export function noteTexts(notes: string[]): string[] {
  const first = notes.findIndex((line) => line.trim() !== '');
  return notes.map((line, index) => (/^\\+## /.test(line) || (index === first && isEscapedField(line, /^\\+- /)) ? line.slice(1) : line));
}
