// SPDX-License-Identifier: AGPL-3.0-or-later

/** Connection loss detection (§5.1): persistent banner while offline, announcement on return. */

import { onLocaleChange, t } from '../i18n';
import { announce, toast } from './announcer';

export function watchConnectivity(): () => void {
  const banner = document.createElement('p');
  banner.className = 'banner offline-banner';
  banner.setAttribute('role', 'status');
  const update = (online: boolean, notify: boolean) => {
    if (online) {
      banner.remove();
      if (notify) toast(t('net.online'));
    } else {
      banner.textContent = t('net.offline');
      // Before <main>, not inside: screen changes replace the content of <main>.
      const main = document.querySelector('main');
      main?.parentElement?.insertBefore(banner, main);
      announce(t('net.offline'), true);
    }
  };
  const onOnline = () => update(true, true);
  const onOffline = () => update(false, true);
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  // Text outside <main>, which page redraws do not reach: translated on each language change.
  const stopTranslating = onLocaleChange(() => {
    if (banner.isConnected) banner.textContent = t('net.offline');
  });
  if (!navigator.onLine) update(false, false);
  return () => {
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
    stopTranslating();
    banner.remove();
  };
}
