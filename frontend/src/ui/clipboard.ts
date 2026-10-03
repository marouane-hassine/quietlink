// SPDX-License-Identifier: AGPL-3.0-or-later

/** Clipboard writes on explicit user action only; reads only on the explicit Paste button. */

import { toast } from './announcer';
import { t } from '../i18n';

export async function copyText(text: string, confirmation: string = t('action.copied')): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    toast(confirmation);
    return true;
  } catch {
    toast(t('action.copyFailed'));
    return false;
  }
}

export function canReadClipboard(): boolean {
  return window.isSecureContext && typeof navigator.clipboard?.readText === 'function';
}
