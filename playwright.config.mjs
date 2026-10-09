import { defineConfig } from '@playwright/test';
const release = process.env.FI_RELEASE_E2E === '1';
export default defineConfig({
  testDir: './tests/E2E', testMatch: '**/*.mjs', timeout: 45000, workers: 1,
  use: { baseURL: 'http://127.0.0.1:8898', headless: true,
    launchOptions: { executablePath: process.env.FI_BROWSER || (process.platform === 'win32' ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined), args: ['--disable-gpu'] },
    screenshot: 'only-on-failure', trace: 'retain-on-failure',
  },
  reporter: [['list'], ['html', { outputFolder: release ? '.runtime/release-playwright-report' : '.runtime/playwright-report', open: 'never' }]],
  outputDir: release ? '.runtime/release-playwright-results' : '.runtime/playwright-results',
});
