// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Could features (§0.3): Wi-Fi QR code, local export, controlled printing.
// Requirements: EXG-TEST-074, EXG-UX-101, EXG-UX-109, EXG-UX-110, EXG-UX-111, EXG-UX-112, EXG-UX-113, EXG-UX-114, EXG-UX-115.

import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { setLocale } from '../src/i18n';
import { escapeWifi, parseTemplateText, wifiPayload } from '../src/templates';
import { renderTemplateView } from '../src/render/template-view';
import { exportButton, printButton } from '../src/ui/local-output';

describe('Wi-Fi QR code', () => {
  setLocale('en');
  it('escapes special characters and maps the security type', () => {
    expect(escapeWifi('a;b,c:d"e\\f')).toBe('a\\;b\\,c\\:d\\"e\\\\f');
    const wpa = parseTemplateText('# Wi-Fi connection\n\n## Network\n- SSID: Dummy;Net\n- Security: WPA2\n- Password: dum:my\n')!;
    expect(wifiPayload(wpa)).toBe('WIFI:T:WPA;S:Dummy\\;Net;P:dum\\:my;;');
    const open = parseTemplateText('# Connexion Wi-Fi\n\n## Réseau\n- SSID : Invités\n- Sécurité : aucune\n')!;
    expect(wifiPayload(open)).toBe('WIFI:T:nopass;S:Invités;;');
    expect(wifiPayload(parseTemplateText('# Wi-Fi connection\n\n## Network\n- SSID:\n')!)).toBeNull();
  });

  it('is hidden until requested and only offered when enabled', () => {
    const template = parseTemplateText('# Wi-Fi connection\n\n## Network\n- SSID: DummyNet\n- Password: dummy-pass\n')!;
    expect(renderTemplateView(template).querySelector('.qr-box')).toBeNull();
    const view = renderTemplateView(template, { wifiQr: true });
    expect(view.querySelector('.qr')).toBeNull();
    (view.querySelector('button[aria-expanded]') as HTMLButtonElement).click();
    expect(view.querySelector('.qr')).not.toBeNull();
  });
});

describe('local export and printing', () => {
  it('exports through a local object URL only', () => {
    const create = vi.fn(() => 'blob:local');
    const revoke = vi.fn();
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke }));
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    exportButton(() => 'dummy export').click();
    expect(create).toHaveBeenCalledOnce();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('asks for confirmation before printing and the print stylesheet hides everything by default', async () => {
    const print = vi.fn();
    vi.stubGlobal('print', print);
    const button = printButton();
    document.body.replaceChildren(button);
    button.click();
    await Promise.resolve();
    expect(document.querySelector('[role=alertdialog]')?.textContent).toContain('Printing leaves the protection of QuietLink');
    expect(print).not.toHaveBeenCalled();
    (document.querySelector('[role=alertdialog] .button-primary') as HTMLButtonElement).click();
    await Promise.resolve();
    expect(print).toHaveBeenCalledOnce();
    expect(document.body.classList.contains('print-allowed')).toBe(true);
    const css = readFileSync(`${process.cwd()}/frontend/src/styles/app.css`, 'utf8');
    expect(css).toMatch(/body:not\(\.print-allowed\) main \{\s*display: none !important;/);
  });
});
