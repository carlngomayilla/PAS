import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';

test('diagnostic local des tableaux remplis au défilement', async ({ page }, testInfo) => {
    const target = process.env.E2E_BASE_URL;
    const email = process.env.E2E_VISUAL_EMAIL;
    const password = process.env.E2E_VISUAL_PASSWORD;
    test.skip(!target || !email || !password, 'Diagnostic local explicite avec compte existant uniquement.');
    expect(new URL(target!).hostname).toMatch(/^(127\.0\.0\.1|localhost)$/);

    const diagnostics = observeBrowser(page, testInfo);
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, { email: email!, password: password! });
    await page.goto('/admin/dashboard?dashboardTab=advanced');
    await expect(page.locator('html')).not.toHaveClass(/dark/);

    const tables = page.locator('table.app-table');
    await expect(tables.first()).toBeVisible();
    expect(await tables.count()).toBeGreaterThan(0);
    const measurements = [];

    for (let index = 0; index < await tables.count(); index++) {
        const table = tables.nth(index);
        await table.scrollIntoViewIfNeeded();
        measurements.push(await table.evaluate(element => {
            const style = (node: Element) => {
                const computed = getComputedStyle(node);
                const rect = node.getBoundingClientRect();
                return {
                    tag: node.tagName,
                    classes: node.className,
                    rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
                    display: computed.display,
                    position: computed.position,
                    overflow: computed.overflow,
                    opacity: computed.opacity,
                    visibility: computed.visibility,
                    color: computed.color,
                    background: computed.backgroundColor,
                    zIndex: computed.zIndex,
                    transform: computed.transform,
                    filter: computed.filter,
                    isolation: computed.isolation,
                };
            };
            const ancestors = [];
            for (let node = element.parentElement; node; node = node.parentElement) {
                ancestors.push(style(node));
            }
            return {
                id: element.id,
                table: style(element),
                cells: Array.from(element.querySelectorAll('th, td')).map(cell => ({
                    text: cell.textContent?.trim(),
                    hidden: cell.hasAttribute('hidden'),
                    ...style(cell),
                })),
                ancestors,
            };
        }));
        await page.screenshot({ path: testInfo.outputPath(`table-${index + 1}-scroll-light.png`) });
    }

    await testInfo.attach('table-rendering-measurements', {
        body: Buffer.from(JSON.stringify(measurements, null, 2)),
        contentType: 'application/json',
    });
    await page.screenshot({ path: testInfo.outputPath('advanced-full-light.png'), fullPage: true });
    diagnostics.assertClean();
});
