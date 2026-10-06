// SPDX-License-Identifier: AGPL-3.0-or-later

import './styles/app.css';
import { readConfig } from './config';
import { loadLocale, selectLocale, setLocale } from './i18n';
import { initTheme, renderChrome, translateTitle } from './ui/chrome';
import { enhanceSkipLink, setInstanceName } from './ui/dom';
import { watchConnectivity } from './ui/connectivity';
import { followActionBar, followVirtualKeyboard } from './ui/viewport';

async function boot(): Promise<void> {
  const main = document.getElementById('main');
  if (!main) return;
  const config = readConfig();
  if (config.name) setInstanceName(config.name);
  enhanceSkipLink();
  const code = selectLocale(config.enabledLocales);
  setLocale((await loadLocale(code)) ? code : 'en');
  const theme = initTheme(config);
  // Each page is a separate chunk; only the current one is loaded.
  const pages = {
    create: async () => (await import('./pages/create')).mountCreate(main, config),
    read: async () => (await import('./pages/read')).mountRead(main, config),
    manage: async () => (await import('./pages/manage')).mountManage(main),
    how: async () => (await import('./pages/how')).mountHow(main, config),
  };
  const rerender = await pages[config.page]();
  // The stored language may differ from the one the server rendered the title in.
  translateTitle(config);
  renderChrome(config, theme, rerender);
  watchConnectivity();
  followVirtualKeyboard();
  followActionBar(main);
}

void boot();
