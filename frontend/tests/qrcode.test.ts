// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-SEC-017, EXG-UX-072, EXG-TEST-079, EXG-TEST-098.

import { describe, expect, it } from 'vitest';
import { qrSvg } from '../src/ui/qrcode';

describe('QR code', () => {
  it('is an SVG built locally with a white opaque background and a quiet zone, without style attributes', () => {
    const svg = qrSvg('https://paste.example.test/p/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA#BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'QR code');
    const size = Number(svg.getAttribute('viewBox')?.split(' ')[2]);
    const background = svg.querySelector('rect.qr-background');
    expect(background?.getAttribute('width')).toBe(String(size));
    const path = svg.querySelector('path')?.getAttribute('d') ?? '';
    const coordinates = [...path.matchAll(/M(\d+) (\d+)/g)].map((m) => [Number(m[1]), Number(m[2])]).flat();
    expect(Math.min(...coordinates)).toBe(4);
    expect(Math.max(...coordinates)).toBe(size - 5);
    expect(svg.outerHTML).not.toContain('style=');
    expect(svg.getAttribute('role')).toBe('img');
  });
});
