import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { credentials } from './helpers/profiles';

test('profil et tâches restent lisibles sur ordinateur et mobile', async ({ page }, testInfo) => {
    await login(page, credentials.planning);
    await page.goto('/workspace/profil');
    await expect(page.locator('.profile-summary-card')).toBeVisible();
    await expect(page.locator('#name')).toBeVisible();
    await expect(page.locator('#email')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    if (testInfo.project.name === 'mobile-chrome') {
        const sessionRow = page.locator('.profile-sessions-card tbody tr').first();
        await expect(sessionRow).toBeVisible();
        const cellsFit = await sessionRow.evaluate(row => Array.from(row.querySelectorAll('td')).every(cell => {
            const bounds = cell.getBoundingClientRect();
            const parent = row.getBoundingClientRect();
            return bounds.left >= parent.left - 1 && bounds.right <= parent.right + 1;
        }));
        expect(cellsFit).toBe(true);
    }
    await page.screenshot({ path: testInfo.outputPath('profile-light.png'), fullPage: true });
    await page.locator('#admin-theme-toggle').click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    const statusContrast = await page.locator('.profile-session-status').first().evaluate(element => {
        const luminance = (color: string) => {
            const channels = color.match(/[\d.]+/g)!.slice(0, 3).map(Number).map(value => {
                const channel = value / 255;
                return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
            });
            return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        };
        const style = getComputedStyle(element);
        const foreground = luminance(style.color);
        const background = luminance(style.backgroundColor);
        return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
    });
    expect(statusContrast).toBeGreaterThanOrEqual(4.5);
    await page.screenshot({ path: testInfo.outputPath('profile-dark.png'), fullPage: true });
    await page.goto('/workspace/mes-taches');
    await expect(page.locator('.personal-task-filters')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('tasks-dark.png'), fullPage: true });
    await page.locator('#admin-theme-toggle').click();
    await page.screenshot({ path: testInfo.outputPath('tasks-light.png'), fullPage: true });

    if (testInfo.project.name === 'chromium') {
        await expect(page.locator('#admin-sidebar-open')).toBeHidden();
        const sidebar = page.locator('#admin-sidebar');
        await page.mouse.move(700, 100);
        await expect.poll(() => sidebar.evaluate(e => e.getBoundingClientRect().width)).toBeLessThan(100);
        await sidebar.hover();
        await expect.poll(() => sidebar.evaluate(e => e.getBoundingClientRect().width)).toBeGreaterThan(200);
        await page.mouse.move(700, 100);
        await expect.poll(() => sidebar.evaluate(e => e.getBoundingClientRect().width)).toBeLessThan(100);
    } else {
        await expect(page.locator('#admin-sidebar-open')).toBeVisible();
    }
});
