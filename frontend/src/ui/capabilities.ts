// SPDX-License-Identifier: AGPL-3.0-or-later

/** Local encryption needs Web Crypto in a secure context; nothing degrades silently (§13). */
export function cryptoAvailable(): boolean {
  return typeof crypto !== 'undefined' && typeof crypto.subtle !== 'undefined' && window.isSecureContext;
}
