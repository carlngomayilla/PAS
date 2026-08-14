import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { expect, Page, test } from '@playwright/test';
import { password } from './helpers/profiles';

const captureDirectory = path.resolve('output/ui-ux-screenshots');

async function login(page: Page, email: string): Promise<void> {
    await page.context().clearCookies();
    await page.goto('/login');
    await page.locator('#loginId').fill(email);
    await page.locator('#loginPwd').fill(password);
    await page.getByRole('button', { name: 'Se connecter' }).click({ noWaitAfter: true });
    await page.waitForURL(url => ! url.pathname.endsWith('/login'), { timeout: 120_000, waitUntil: 'commit' });
}

async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate(selectedTheme => {
        document.documentElement.classList.toggle('dark', selectedTheme === 'dark');
        document.documentElement.setAttribute('data-theme', selectedTheme);
    }, theme);
}

async function capture(page: Page, name: string, fullPage = true): Promise<void> {
    await page.screenshot({ path: path.join(captureDirectory, `${name}.png`), fullPage });
}

test('captures de référence UI/UX avant modification', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium');
    test.setTimeout(600_000);
    await mkdir(captureDirectory, { recursive: true });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await capture(page, 'login-desktop-light', false);
    await page.setViewportSize({ width: 390, height: 844 });
    await capture(page, 'login-mobile-390-light', false);
    await page.setViewportSize({ width: 320, height: 568 });
    await capture(page, 'login-mobile-320-light', false);

    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'super.admin.e2e@example.test');
    await setTheme(page, 'light');
    await capture(page, 'super-admin-dashboard-desktop-light', false);
    await setTheme(page, 'dark');
    await capture(page, 'super-admin-dashboard-desktop-dark', false);

    await login(page, 'chef.service.e2e@example.test');
    await page.goto('/workspace/reunions', { waitUntil: 'domcontentloaded' });
    await setTheme(page, 'light');
    await capture(page, 'meetings-desktop-light');
    await setTheme(page, 'dark');
    await capture(page, 'meetings-desktop-dark');

    await page.setViewportSize({ width: 390, height: 844 });
    await setTheme(page, 'light');
    await capture(page, 'meetings-mobile-390-light');

    await page.setViewportSize({ width: 320, height: 568 });
    await login(page, 'agent.e2e@example.test');
    await setTheme(page, 'light');
    await capture(page, 'agent-dashboard-mobile-320-light');

    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'planification.e2e@example.test');
    await setTheme(page, 'light');
    await capture(page, 'planification-sidebar-desktop-light', false);

    await login(page, 'ucas.e2e@example.test');
    await capture(page, 'ucas-after-login-desktop', false);
    await expect(page).toHaveURL(/\/workspace|\/dashboard|\/login|\/403|\/forbidden/);
});
