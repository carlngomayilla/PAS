import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { password } from './helpers/profiles';

test('notifications et référentiel conservent une mise en page lisible', async ({ page }, testInfo) => {
    await login(page, { email: 'super.admin.e2e@example.test', password });
    const screens = [
        ['notifications', '/workspace/notifications', '.notification-workspace'],
        ['alertes', '/workspace/notifications?tab=alertes', '.notification-workspace'],
        ['directions', '/workspace/referentiel/directions', '.reference-workspace'],
        ['services', '/workspace/referentiel/services', '.reference-workspace'],
        ['utilisateurs', '/workspace/referentiel/utilisateurs', '.reference-workspace'],
    ];
    for (const [name, url, selector] of screens) {
        await page.goto(url);
        await expect(page.locator(selector)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        for (const table of await page.locator('.reference-table').all()) {
            expect(await table.evaluate(e => e.scrollWidth <= e.parentElement!.clientWidth + 1)).toBe(true);
        }
        await page.screenshot({ path: testInfo.outputPath(`${name}-light.png`), fullPage: !url.includes('referentiel'), scale: 'css' });
        await page.locator('#admin-theme-toggle').click();
        await expect(page.locator('html')).toHaveClass(/dark/);
        await page.screenshot({ path: testInfo.outputPath(`${name}-dark.png`), fullPage: !url.includes('referentiel'), scale: 'css' });
        if (url.includes('referentiel')) {
            await page.locator('.reference-table tbody tr').first().scrollIntoViewIfNeeded();
            await page.screenshot({ path: testInfo.outputPath(`${name}-dark-list.png`), scale: 'css' });
        }
        await page.locator('#admin-theme-toggle').click();
    }
    if (testInfo.project.name === 'chromium') {
        await page.setViewportSize({ width: 820, height: 900 });
        await page.goto('/workspace/referentiel/utilisateurs');
        const cell = page.locator('.reference-table td[data-label]').first();
        await expect(cell).toBeVisible();
        const label = await cell.getAttribute('data-label');
        expect(await cell.evaluate(e => getComputedStyle(e, '::before').content)).toContain(label!);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.screenshot({ path: testInfo.outputPath('utilisateurs-tablet.png') });
    }
});
