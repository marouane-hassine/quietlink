// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-MD (templates §6.1.1), EXG-UX-042, EXG-UX-044.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { isSensitiveLabel, parseTemplateText, renderTemplate, serializeTemplate } from '../src/templates';

describe('template text', () => {
  it('round-trips a filled template through parse and serialize', () => {
    setLocale('fr');
    const text = renderTemplate('credentials').replace('- Mot de passe :', '- Mot de passe : dummy-value');
    const parsed = parseTemplateText(text);
    expect(parsed?.title).toBe('Identifiants de connexion');
    const identity = parsed?.sections.find((s) => s.title === 'Identité');
    expect(identity?.fields.find((f) => f.label === 'Mot de passe')?.value).toBe('dummy-value');
    expect(serializeTemplate(parsed!)).toBe(text);
  });

  it('keeps free text lines of a section', () => {
    setLocale('en');
    const parsed = parseTemplateText('# Technical incident\n\n## Context\nServer dummy-01 restarted\n');
    expect(parsed?.sections[0]?.notes).toEqual(['Server dummy-01 restarted']);
  });

  it('returns null for text that is not a template', () => {
    expect(parseTemplateText('just a sentence')).toBeNull();
  });

  it('detects sensitive fields in every catalog language', () => {
    for (const label of ['Password', 'Mot de passe', 'Token', 'Valeur', 'Key or local path']) expect(isSensitiveLabel(label)).toBe(true);
    for (const label of ['URL', 'Hôte', 'Username']) expect(isSensitiveLabel(label)).toBe(false);
  });
});
