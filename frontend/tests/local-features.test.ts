// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Could features (§0.3): Wi-Fi QR code, local export, controlled printing.
// Requirements: EXG-TEST-074, EXG-UX-101, EXG-UX-109, EXG-UX-110, EXG-UX-111, EXG-UX-112, EXG-UX-113, EXG-UX-114, EXG-UX-115, EXG-MD-018, EXG-TEST-080.

import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { setLocale, t } from '../src/i18n';
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
    const wifi = (security: string, password: string) => wifiPayload(parseTemplateText(`# Wi-Fi connection\n\n## Network\n- SSID: Net\n- Security: ${security}\n- Password: ${password}\n`)!);
    // A password that was entered is never dropped, whatever the security wording.
    expect(wifi('WPA2 / WPA3 / none', 'dummy')).toBe('WIFI:T:WPA;S:Net;P:dummy;;');
    // WPA3 only (SAE); transition networks (WPA2/WPA3) keep WPA, which every phone accepts.
    expect(wifi('WPA3', 'dummy')).toBe('WIFI:T:SAE;S:Net;P:dummy;;');
    expect(wifi('WPA3-Personal', 'dummy')).toBe('WIFI:T:SAE;S:Net;P:dummy;;');
    expect(wifi('WPA2/WPA3', 'dummy')).toBe('WIFI:T:WPA;S:Net;P:dummy;;');
    expect(wifi('Aucune', 'dummy')).toBe('WIFI:T:WPA;S:Net;P:dummy;;');
    expect(wifi('WPA2/WEP', 'dummy')).toBe('WIFI:T:WPA;S:Net;P:dummy;;');
    expect(wifi('WEP', 'dummy')).toBe('WIFI:T:WEP;S:Net;P:dummy;;');
    expect(wifi('Ouverte', '')).toBe('WIFI:T:nopass;S:Net;;');
  });

  it('is hidden until requested and only offered when enabled', () => {
    const template = parseTemplateText('# Wi-Fi connection\n\n## Network\n- SSID: DummyNet\n- Password: dummy-pass\n')!;
    expect(renderTemplateView(template).querySelector('.qr-box')).toBeNull();
    const view = renderTemplateView(template, { wifiQr: true });
    expect(view.querySelector('.qr')).toBeNull();
    (view.querySelector('button[aria-expanded]') as HTMLButtonElement).click();
    expect(view.querySelector('.qr')).not.toBeNull();
  });

  it('shows a translated message instead of an empty box past the QR capacity', () => {
    setLocale('en');
    const template = parseTemplateText(`# Wi-Fi connection\n\n## Network\n- SSID: DummyNet\n- Password: ${'p'.repeat(3000)}\n`)!;
    const view = renderTemplateView(template, { wifiQr: true });
    expect(() => (view.querySelector('button[aria-expanded]') as HTMLButtonElement).click()).not.toThrow();
    const box = view.querySelector('.qr-box') as HTMLElement;
    expect(box.hidden).toBe(false);
    expect(box.querySelector('.qr')).toBeNull();
    expect(box.textContent).toBe(t('tpl.wifiQrTooLong'));
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

  it('drops the key fragment from the address while printing and restores it afterwards', async () => {
    const key = '#' + 'k'.repeat(43);
    history.replaceState(null, '', '/p/AAAA' + key);
    let printedUrl = '';
    vi.stubGlobal('print', vi.fn(() => (printedUrl = location.href)));
    const button = printButton();
    document.body.replaceChildren(button);
    button.click();
    await Promise.resolve();
    (document.querySelector('[role=alertdialog] .button-primary') as HTMLButtonElement).click();
    await Promise.resolve();

    expect(printedUrl).not.toContain('#');
    expect(printedUrl).toContain('/p/AAAA');
    window.dispatchEvent(new Event('afterprint'));
    expect(location.hash).toBe(key);
    expect(document.body.classList.contains('print-allowed')).toBe(false);
  });
});

describe('Wi-Fi hidden networks', () => {
  const hidden = (label: string, value: string) => wifiPayload(parseTemplateText(`# Wi-Fi connection\n\n## Network\n- SSID: Net\n- Security: WPA2\n- Password: dummy\n- ${label}: ${value}\n`)!);

  it('adds H:true when the network is marked hidden, in any shipped language', () => {
    for (const [label, value] of [['Hidden network', 'yes'], ['Réseau masqué', 'oui'], ['Red oculta', 'sí'], ['Rete nascosta', 'sì'], ['شبكة مخفية', 'نعم']]) {
      expect(hidden(label as string, value as string)).toBe('WIFI:T:WPA;S:Net;P:dummy;H:true;;');
    }
    expect(hidden('Hidden network', 'no')).toBe('WIFI:T:WPA;S:Net;P:dummy;;');
  });
});


describe('Wi-Fi hidden network flag (§6.1.1)', () => {
  const hidden = (value: string) => wifiPayload(parseTemplateText(`# Wi-Fi connection\n\n## Network\n- SSID: Net\n- Password: dummy\n- Hidden network: ${value}\n`)!);

  it('reads the first word, normalised (NFC, lower case), in every shipped language', () => {
    for (const value of ['yes', 'Yes, hidden', 'Oui (masqué)', 'OUI', 'sí', 'sí', 'Sì, nascosta', 'نعم']) {
      expect(hidden(value), value).toBe('WIFI:T:WPA;S:Net;P:dummy;H:true;;');
    }
    for (const value of ['no', 'non', 'yesterday', 'not yes']) expect(hidden(value), value).toBe('WIFI:T:WPA;S:Net;P:dummy;;');
  });
});
