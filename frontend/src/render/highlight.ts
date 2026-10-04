// SPDX-License-Identifier: AGPL-3.0-or-later

/** Syntax highlighting with classes only (no style attribute, CSP style-src 'self'). */

import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import dockerfile from 'highlight.js/lib/languages/dockerfile';
import go from 'highlight.js/lib/languages/go';
import ini from 'highlight.js/lib/languages/ini';
import java from 'highlight.js/lib/languages/java';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import python from 'highlight.js/lib/languages/python';
import rust from 'highlight.js/lib/languages/rust';
import sql from 'highlight.js/lib/languages/sql';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';

const LANGUAGES = { bash, css, dockerfile, go, ini, java, javascript, json, php, python, rust, sql, typescript, xml, yaml };
for (const [name, language] of Object.entries(LANGUAGES)) hljs.registerLanguage(name, language);

export { HIGHLIGHT_LIMIT_BYTES, LANGUAGE_IDS } from './languages';

/**
 * highlight.js grows quadratically with line length (100 KB of hex on one line blocks the Java
 * grammar for ~24 s): code with long lines or large blocks is shown plain, so formatting never
 * freezes the interface (§5.1). With short lines the cost is linear (~440 ms worst for 64 KiB).
 */
export const HIGHLIGHT_MAX_LINE = 500;
export const HIGHLIGHT_MAX_BLOCK = 64 * 1024;

export function withinHighlightBudget(code: string): boolean {
  if (code.length > HIGHLIGHT_MAX_BLOCK) return false;
  let start = 0;
  while (start <= code.length) {
    const end = code.indexOf('\n', start);
    const stop = end === -1 ? code.length : end;
    if (stop - start > HIGHLIGHT_MAX_LINE) return false;
    start = stop + 1;
  }
  return true;
}

/** Escaped HTML with hljs classes, or null when the language is unknown or the code too costly. */
export function highlightToHtml(code: string, language: string): string | null {
  if (!language || !hljs.getLanguage(language) || !withinHighlightBudget(code)) return null;
  return hljs.highlight(code, { language, ignoreIllegals: true }).value;
}
