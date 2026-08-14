import { defineConfig, devices } from '@playwright/test';
import { appEnvironment, baseURL } from './tests/e2e/environment';

const managesLocalServer = !process.env.E2E_BASE_URL;

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    timeout: 300_000,
    expect: { timeout: 30_000 },
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    globalSetup: managesLocalServer ? './tests/e2e/global-setup.ts' : undefined,
    outputDir: 'test-results',
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL,
        locale: 'fr-FR',
        timezoneId: 'Africa/Libreville',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 30_000,
        navigationTimeout: 60_000,
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
        { name: 'mobile-chrome', use: { ...devices['Pixel 7'] } },
    ],
    webServer: managesLocalServer ? {
        command: 'php artisan serve --host=127.0.0.1 --port=8765 --no-reload',
        url: `${baseURL}/up`,
        reuseExistingServer: false,
        timeout: 120_000,
        env: appEnvironment,
        stdout: 'ignore',
        stderr: 'pipe',
    } : undefined,
});
