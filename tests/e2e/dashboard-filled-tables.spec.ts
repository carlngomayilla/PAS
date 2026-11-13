import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';

test('les tableaux renseignés restent lisibles après défilement', async ({ page }, testInfo) => {
    test.skip(!process.env.E2E_FILLED_TABLE_EMAIL, 'Compte local avec tableaux renseignés requis.');
    await login(page, {
        email: process.env.E2E_FILLED_TABLE_EMAIL!,
        password: process.env.E2E_FILLED_TABLE_PASSWORD!,
    });
    await page.goto('/admin/dashboard?dashboardTab=advanced');
    const table = page.locator('table').filter({ hasText: 'Action test SIRS 1' }).first();
    await expect(table).toBeVisible();
    const fixedBackgrounds = await table.evaluate(element => {
        const result: string[] = [];
        for (let node: Element | null = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            if (style.backgroundAttachment.includes('fixed') && style.backgroundImage !== 'none') {
                result.push(node.className);
            }
        }
        return result;
    });
    expect(fixedBackgrounds).toEqual([]);
    await table.scrollIntoViewIfNeeded();
    const rows = table.locator('tbody tr:not([data-table-generated-row])');
    expect(await rows.count()).toBeGreaterThan(0);
    for (const row of await rows.all()) {
        await expect(row).toBeVisible();
    }
    await page.screenshot({ path: testInfo.outputPath('filled-tables-baseline.png') });
    await page.mouse.wheel(0, 400);
    await page.waitForTimeout(500);
    await page.mouse.wheel(0, -400);
    await page.waitForTimeout(500);
    await page.screenshot({ path: testInfo.outputPath('filled-tables-after-scroll.png') });
    await table.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await page.screenshot({ path: testInfo.outputPath('filled-tables-without-clipping.png') });
    const secondTable = page.locator('table').filter({ hasText: 'Action test SIRS 1' }).nth(1);
    await secondTable.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await expect(secondTable.locator('tbody tr:not([data-table-generated-row])')).toHaveCount(4);
    await page.screenshot({ path: testInfo.outputPath('filled-tables-second-table.png') });
});
