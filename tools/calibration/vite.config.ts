// SPDX-License-Identifier: AGPL-3.0-or-later
// Local calibration server: `npm run calibration`, then open the printed LAN URL on each device.
import { defineConfig } from 'vite';

export default defineConfig({
  root: 'tools/calibration',
  server: { host: true, port: 5180, fs: { allow: ['../..'] } },
  worker: { format: 'es' },
});
