// SPDX-License-Identifier: AGPL-3.0-or-later

import './styles/app.css';
import { readConfig } from './config';
import { selectLocale, setLocale } from './i18n';
import { initTheme, renderChrome } from './ui/chrome';
import { watchConnectivity } from './ui/connectivity';
import { followVirtualKeyboard } from './ui/viewport';

async function boot(): Promise<void> {
  const main = document.getElementById('main');
  if (!main) return;
  const config = readConfig();
  setLocale(selectLocale(config.enabledLocales));
  const theme = initTheme(config);
  // Each page is a separate chunk; only the current one is loaded.
  const pages = {
    create: async () => (await import('./pages/create')).mountCreate(main, config),
    read: async () => (await import('./pages/read')).mountRead(main, config),
    manage: async () => (await import('./pages/manage')).mountManage(main),
    how: async () => (await import('./pages/how')).mountHow(main),
  };
  const rerender = await pages[config.page]();
  renderChrome(config, theme, rerender);
  watchConnectivity();
  followVirtualKeyboard();
}

void boot();
