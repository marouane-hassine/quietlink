// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-MD (templates §6.1.1), EXG-UX-042, EXG-UX-044.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { compactTemplateText, isSensitiveLabel, templateIsBlank, parseTemplateText, renderTemplate, serializeTemplate } from '../src/templates';

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

  it('never reads a "##" heading with a blank title as a section (round trip, EXG-MD-008)', () => {
    const text = '# T\n\n## S\nnote\n\n##   ';
    const parsed = parseTemplateText(text);
    if (parsed !== null) {
      expect(parsed.sections.every((section) => section.title.trim() !== '')).toBe(true);
      expect(parseTemplateText(serializeTemplate(parsed))).toEqual(parsed);
    }
    expect(parseTemplateText('# T\n\n##   \n- A: 1\n')).toBeNull();
  });

  it('returns null for text that is not a template', () => {
    expect(parseTemplateText('just a sentence')).toBeNull();
  });

  it('detects sensitive fields in every catalog language', () => {
    for (const label of ['Password', 'Mot de passe', 'Token', 'Valeur', 'Key or local path']) expect(isSensitiveLabel(label)).toBe(true);
    for (const label of ['URL', 'Hôte', 'Username']) expect(isSensitiveLabel(label)).toBe(false);
  });
});

describe('lossless template editing (§6.1.1, §6.2)', () => {
  it('accepts only texts the form writes back unchanged', () => {
    // A field line after notes stays a note line: nothing moves, the text round-trips.
    const notesFirst = '# T\n\n## S\nnote first\n- A: 1\n';
    expect(serializeTemplate(parseTemplateText(notesFirst)!)).toBe(notesFirst);
    // A blank line inside a code block would disappear and "## " in it would start a section.
    expect(parseTemplateText('# T\n\n## S\n- A: x\n```\nl1\n\nl2\n## not section\n```\n')).toBeNull();
  });

  it('accepts every generated template and keeps shell comments in notes', () => {
    setLocale('en');
    const text = renderTemplate('incident');
    expect(parseTemplateText(text)).not.toBeNull();
    const parsed = parseTemplateText(text)!;
    const section = parsed.sections.find((s) => s.fields.length === 0) ?? parsed.sections[0]!;
    section.notes = ['```bash', '# restart nginx', 'systemctl restart nginx', '```'];
    const written = serializeTemplate(parsed);
    expect(written).toContain('# restart nginx');
    expect(parseTemplateText(written)).not.toBeNull();
  });
});

describe('stored template text (EXG-MD-029)', () => {
  it('drops empty fields and sections left without content before encryption', () => {
    const text = '# Login credentials\n\n## Service\n- Name: dummy-service\n- URL:\n\n## Security\n- Expiry date:\n\n## Notes\nkeep this note\n';
    expect(compactTemplateText(text)).toBe('# Login credentials\n\n## Service\n- Name: dummy-service\n\n## Notes\nkeep this note\n');
  });

  it('keeps a label whose value was typed on the next line (a note of its section)', () => {
    const text = '# Login credentials\n\n## Identity\n- Username: bob\n- Password:\ndummy-pw-123\n';
    expect(compactTemplateText(text)).toBe(text);
  });

  it('treats blank or space-only values as empty, a trailing space included', () => {
    const text = '# Login credentials\n\n## Service\n- Name: svc\n- URL: \n- Environment:   \n\n## Notes\n';
    expect(compactTemplateText(text)).toBe('# Login credentials\n\n## Service\n- Name: svc\n');
  });

  it('tells a template with nothing filled in from free text', () => {
    expect(templateIsBlank(renderTemplate('credentials'))).toBe(true);
    expect(templateIsBlank(renderTemplate('credentials').replace(/^(- [^\n]*:)$/m, '$1   '))).toBe(true);
    expect(templateIsBlank('# my-dummy-token-value')).toBe(false);
    expect(templateIsBlank('# Login credentials\n\n## Service\n- Name: svc\n')).toBe(false);
  });

  it('leaves text that is not a template unchanged', () => {
    expect(compactTemplateText('plain text\n- URL:\n')).toBe('plain text\n- URL:\n');
  });
});
