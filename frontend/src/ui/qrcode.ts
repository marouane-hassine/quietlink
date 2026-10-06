// SPDX-License-Identifier: AGPL-3.0-or-later

/** QR codes (share link, Wi-Fi template) generated locally as SVG built through the DOM. */

import qrcode from 'qrcode-generator';

// The default keeps only the low byte of each UTF-16 unit: "é" or Arabic letters would be
// corrupted (U+063A becomes ":", a Wi-Fi payload delimiter). Byte mode carries UTF-8.
qrcode.stringToBytes = (text: string): number[] => Array.from(new TextEncoder().encode(text));

const SVG = 'http://www.w3.org/2000/svg';

/** The text exceeds the capacity of a QR code (about 2.3 KB at correction level M). */
export class QrTooLongError extends Error {}

export function qrSvg(text: string, label: string): SVGSVGElement {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  try {
    qr.make();
  } catch {
    throw new QrTooLongError('Text too long for a QR code.');
  }
  const count = qr.getModuleCount();
  const quiet = 4;
  const size = count + quiet * 2;
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', label);
  svg.classList.add('qr');
  const background = document.createElementNS(SVG, 'rect');
  background.setAttribute('width', String(size));
  background.setAttribute('height', String(size));
  background.setAttribute('class', 'qr-background');
  svg.append(background);
  let d = '';
  for (let row = 0; row < count; row++) {
    for (let col = 0; col < count; col++) {
      if (qr.isDark(row, col)) d += `M${col + quiet} ${row + quiet}h1v1h-1z`;
    }
  }
  const path = document.createElementNS(SVG, 'path');
  path.setAttribute('d', d);
  path.setAttribute('class', 'qr-modules');
  svg.append(path);
  return svg;
}
