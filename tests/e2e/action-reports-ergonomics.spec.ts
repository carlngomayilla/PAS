import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { password } from './helpers/profiles';

test('actions et rapports restent utilisables sur petit écran', async ({ page }, testInfo) => {
    await login(page, { email: 'super.admin.e2e@example.test', password });
    const screens = [
        ['actions', '/workspace/actions', '.action-list-workspace'],
        ['rapports', '/workspace/rapports', '.reports-workspace'],
        ['reporting', '/workspace/reporting', '.reports-workspace'],
        ['conformite', '/workspace/reporting?report_type=conformite', '.reports-workspace'],
        ['pta-officiel', '/workspace/reporting?report_type=pta', '.reports-workspace'],
    ];
    for (const [name, url, selector] of screens) {
        await page.goto(url);
        await expect(page.locator(selector)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        for (const table of await page.locator('.action-list-table, .reports-table').all()) {
            expect(await table.evaluate(e => e.scrollWidth <= e.parentElement!.clientWidth + 1)).toBe(true);
        }
        if (name === 'actions' && testInfo.project.name === 'mobile-chrome') {
            const visibility = await page.locator('.action-list-table').evaluate(table => {
                const row = document.createElement('tr');
                row.hidden = true;
                const hiddenRow = document.createElement('tr');
                hiddenRow.className = 'hidden';
                const visibleRow = document.createElement('tr');
                const cell = document.createElement('td');
                cell.setAttribute('data-mobile-hidden', '');
                visibleRow.append(cell);
                const body = table.querySelector('tbody')!;
                body.append(row, hiddenRow, visibleRow);
                const result = [row, hiddenRow, cell].map(e => getComputedStyle(e).display);
                row.remove(); hiddenRow.remove(); visibleRow.remove();
                return result;
            });
            expect(visibility).toEqual(['none', 'none', 'none']);
        }
        await page.screenshot({ path: testInfo.outputPath(`${name}-light.png`), scale: 'css' });
        await page.locator('#admin-theme-toggle').click();
        await expect(page.locator('html')).toHaveClass(/dark/);
        await page.screenshot({ path: testInfo.outputPath(`${name}-dark.png`), scale: 'css' });
        await page.locator('#admin-theme-toggle').click();
    }
});

test('le contexte des actions renseignées reste accessible sur tablette', async ({ page }, testInfo) => {
    test.skip(!process.env.E2E_FILLED_TABLE_EMAIL, 'Compte local avec actions renseignées requis.');
    await login(page, { email: process.env.E2E_FILLED_TABLE_EMAIL!, password: process.env.E2E_FILLED_TABLE_PASSWORD! });
    await page.setViewportSize({ width: 820, height: 900 });
    await page.goto('/workspace/actions?q=Action%20test%20SIRS');
    const context = page.locator('[data-action-mobile-context]').first();
    await expect(context).toBeVisible();
    await context.locator('summary').click();
    await expect(context).toContainText('Justificatif');
    await expect(context).toContainText('Validation');
    await context.scrollIntoViewIfNeeded();
    await expect(page.locator('.action-list-table td[data-mobile-hidden]').first()).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('actions-filled-tablet.png'), scale: 'css' });
});
