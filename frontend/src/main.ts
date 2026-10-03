// SPDX-License-Identifier: AGPL-3.0-or-later

import './styles/app.css';
import { readConfig } from './config';
import { selectLocale, setLocale } from './i18n';
import { mountCreate } from './pages/create';
import { mountHow } from './pages/how';
import { mountManage } from './pages/manage';
import { mountRead } from './pages/read';
import { initTheme, renderChrome } from './ui/chrome';
import { watchConnectivity } from './ui/connectivity';

function boot(): void {
  const main = document.getElementById('main');
  if (!main) return;
  const config = readConfig();
  setLocale(selectLocale(config.enabledLocales));
  const theme = initTheme(config);
  const mount = { create: () => mountCreate(main, config), read: () => mountRead(main, config), manage: () => mountManage(main), how: () => mountHow(main) }[config.page];
  const rerender = mount();
  renderChrome(config, theme, rerender);
  watchConnectivity();
}

boot();
