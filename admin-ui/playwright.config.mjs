import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './browser-tests',
  use: { baseURL: 'http://127.0.0.1:4174', browserName: 'chromium' },
  webServer: { command: 'node ../widget-embed/test-server.mjs', port: 4174, reuseExistingServer: false },
  reporter: 'list',
});
