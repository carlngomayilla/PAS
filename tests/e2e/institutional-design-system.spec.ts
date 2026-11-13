import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';
import { credentials } from './helpers/profiles';

test.describe('système de design institutionnel', () => {
    test('le centre de décision reste lisible en clair, sombre et mobile', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name === 'firefox');
        const diagnostics = observeBrowser(page, testInfo);

        await login(page, credentials.planning);
        await page.goto('/dashboard');

        const commandCenter = page.locator('[data-dashboard-command-center]');
        const priorityZone = page.locator('[data-dashboard-insight-zone]');
        const hierarchy = page.locator('[data-dashboard-synthesis-hierarchy]');

        await expect(commandCenter).toBeVisible();
        await expect(priorityZone).toBeVisible();
        await expect(priorityZone).toContainText('À traiter aujourd’hui');
        await expect(hierarchy).toBeVisible();

        const axisNodes = hierarchy.locator('.dashboard-synthesis-node-axis');
        if (await axisNodes.count()) {
            await expect(axisNodes.first()).toHaveAttribute('open', '');
            await expect(hierarchy.locator('.dashboard-synthesis-node-strategic-objective').first()).not.toHaveAttribute('open', '');
        } else {
            await expect(hierarchy).toContainText('Aucune synthèse PAS');
        }

        const filterForm = page.locator('[data-dashboard-synthesis-filter-form]');
        const primaryFilters = filterForm.locator('[data-dashboard-primary-filters]');
        const additionalFilters = filterForm.locator('[data-dashboard-additional-filters]');
        await expect(primaryFilters.locator('select[name="exercice"]')).toBeVisible();
        await expect(primaryFilters.locator('select[name="periode"]')).toBeVisible();
        await expect(additionalFilters).not.toHaveAttribute('open', '');
        await additionalFilters.locator('summary').click();
        await expect(additionalFilters.locator('select[name="statut_action"]')).toBeVisible();
        await additionalFilters.locator('summary').click();
        await expect(additionalFilters.locator('select[name="statut_action"]')).not.toBeVisible();

        const viewportFits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
        expect(viewportFits).toBe(true);

        const lightSurface = await commandCenter.evaluate(element => getComputedStyle(element).backgroundColor);
        expect(lightSurface).not.toBe('rgba(0, 0, 0, 0)');

        await page.screenshot({
            path: testInfo.outputPath(`institutional-${testInfo.project.name}-light.png`),
            fullPage: true,
        });

        const themeToggle = page.locator('#admin-theme-toggle');
        await themeToggle.click();
        await expect(page.locator('html')).toHaveClass(/dark/);
        await expect(themeToggle).toHaveAttribute('aria-pressed', 'true');
        await expect(themeToggle).toHaveAttribute('aria-label', 'Activer le thème clair');

        const darkSurface = await commandCenter.evaluate(element => getComputedStyle(element).backgroundColor);
        expect(darkSurface).not.toBe(lightSurface);

        await page.screenshot({
            path: testInfo.outputPath(`institutional-${testInfo.project.name}-dark.png`),
            fullPage: true,
        });

        await page.goto('/dashboard?dashboardTab=advanced');
        const tables = page.locator('table.app-table');
        if (await tables.count()) {
            await expect(tables.first()).toBeVisible();
            await tables.first().scrollIntoViewIfNeeded();
        } else {
            await expect(page.getByText('Aucune donnée pour les tableaux', { exact: true })).toBeVisible();
        }
        await page.screenshot({
            path: testInfo.outputPath(`institutional-${testInfo.project.name}-tables.png`),
        });

        diagnostics.assertClean();
    });
});
