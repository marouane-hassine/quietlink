// SPDX-License-Identifier: AGPL-3.0-or-later

/** Markdown templates (§6.1.1), filled entirely in the browser. Labels come from the catalogs. */

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
    { title: 'network', fields: ['name', 'ssid', 'security', 'password'] },
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
