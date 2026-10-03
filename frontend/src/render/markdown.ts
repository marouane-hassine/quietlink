// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Markdown rendering of untrusted decrypted content: markdown-it with raw HTML disabled,
 * http/https links only, no images (alt text and URL as plain text), then DOMPurify with an
 * explicit allowlist. Returns DOM nodes (no innerHTML sink).
 */

import DOMPurify from 'dompurify';
import MarkdownIt from 'markdown-it';

type Renderer = InstanceType<typeof MarkdownIt>;
import { HIGHLIGHT_MAX_BLOCK, highlightToHtml } from './highlight';
import { t } from '../i18n';

const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'del', 's', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span'];
const ALLOWED_ATTR = ['href', 'title', 'class', 'rel', 'target'];

export function isAllowedUrl(url: string): boolean {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' || parsed.protocol === 'http:';
  } catch {
    return false;
  }
}

/** Characters left to highlight in the document being rendered. */
let highlightBudget = HIGHLIGHT_MAX_BLOCK;

function createRenderer(): Renderer {
  const md = new MarkdownIt({ html: false, linkify: false, typographer: false, highlight: (code, lang) => {
    // Total highlighted per document is bounded too (§5.1): later blocks stay plain.
    if (code.length > highlightBudget) return '';
    const html = highlightToHtml(code, lang);
    if (html !== null) highlightBudget -= code.length;
    return html ?? '';
  } });
  md.validateLink = isAllowedUrl;
  md.renderer.rules.image = (tokens, idx) => {
    const token = tokens[idx];
    const alt = token?.content ?? '';
    const src = token?.attrGet('src') ?? '';
    return md.utils.escapeHtml(t('read.imageText', { alt, url: src }));
  };
  const defaultLinkOpen = md.renderer.rules.link_open ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options));
  md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    const token = tokens[idx];
    if (token) {
      const href = String(token.attrGet('href') ?? '');
      let host = href;
      try {
        host = new URL(href).host;
      } catch {
        // validateLink already rejected invalid URLs.
      }
      token.attrSet('rel', 'noopener noreferrer nofollow');
      token.attrSet('target', '_blank');
      token.attrSet('class', 'external-link');
      token.attrSet('title', t('read.linkTarget', { url: host }));
    }
    return defaultLinkOpen(tokens, idx, options, env, self);
  };
  return md;
}

let renderer: Renderer | null = null;

export function renderMarkdown(source: string): DocumentFragment {
  renderer ??= createRenderer();
  highlightBudget = HIGHLIGHT_MAX_BLOCK;
  const html = renderer.render(source);
  const fragment = DOMPurify.sanitize(html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP: /^https?:/i,
    FORBID_ATTR: ['style'],
    RETURN_DOM_FRAGMENT: true,
  });
  // Applied after sanitisation so that the allowlist cannot drop them (§6.9).
  for (const link of fragment.querySelectorAll('a')) {
    if (!isAllowedUrl(link.getAttribute('href') ?? '')) {
      link.removeAttribute('href');
      continue;
    }
    link.setAttribute('rel', 'noopener noreferrer nofollow');
    link.setAttribute('target', '_blank');
  }
  return fragment;
}
