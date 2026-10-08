import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';
import { credentials } from './helpers/profiles';

test.describe('stabilisation du dashboard', () => {
    test('les onglets, filtres automatiques et liens de détail fonctionnent dans le navigateur', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'chromium');
        const diagnostics = observeBrowser(page, testInfo);

        await login(page, credentials.planning);
        await page.goto('/dashboard');

        const themeToggle = page.locator('#admin-theme-toggle');
        const html = page.locator('html');
        const body = page.locator('body');

        if (await html.evaluate(element => element.classList.contains('dark'))) {
            await themeToggle.click();
            await expect(html).not.toHaveClass(/dark/);
        }

        await expect(body).toHaveCSS('background-color', 'rgb(238, 242, 247)');
        await expect(page.locator('#admin-shell-header')).toHaveCSS('position', 'sticky');

        const primaryButtons = page.locator([
            '.app-btn-primary',
            '.btn-primary',
            '.btn-blue',
            '.btn-follow',
            '.app-button-primary',
            '.page-header-button-primary',
            '.pta-inline-save',
        ].join(', '));

        if (await primaryButtons.count() > 0) {
            await expect(primaryButtons.first()).toHaveCSS('border-radius', '10px');
        }

        try {
            await themeToggle.click();
            await expect(html).toHaveClass(/dark/);
            await expect(body).toHaveCSS('background-color', 'rgb(6, 11, 19)');
        } finally {
            if (await html.evaluate(element => element.classList.contains('dark'))) {
                await themeToggle.click();
            }
            await expect(html).not.toHaveClass(/dark/);
            await expect(body).toHaveCSS('background-color', 'rgb(238, 242, 247)');
        }

        await expect(page.getByRole('tab')).toHaveText(['Pilotage', 'Tableaux', 'Graphiques']);

        const filterForm = page.locator('[data-dashboard-synthesis-filter-form]');
        await expect(filterForm).toBeVisible();
        await expect(filterForm.locator('button[type="submit"], input[type="submit"]')).toHaveCount(0);

        const statusSelect = filterForm.locator('select[name="statut_suivi"]');
        const statusValue = await statusSelect.locator('option').evaluateAll(options => {
            const candidate = options.find(option => ! ['', 'all'].includes((option as HTMLOptionElement).value));

            return (candidate as HTMLOptionElement | undefined)?.value ?? '';
        });
        expect(statusValue).not.toBe('');

        const navigations: string[] = [];
        page.on('request', request => {
            if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
                navigations.push(request.url());
            }
        });

        await Promise.all([
            page.waitForURL(url => url.pathname === '/dashboard' && url.searchParams.get('statut_suivi') === statusValue),
            statusSelect.selectOption(statusValue),
        ]);

        expect(navigations.filter(url => ['/dashboard', '/synthese'].includes(new URL(url).pathname))).toHaveLength(1);
        await expect(page.locator('[data-dashboard-synthesis-filter-form] select[name="statut_suivi"]')).toHaveValue(statusValue);

        await Promise.all([
            page.waitForURL(url => url.pathname === '/dashboard' && url.searchParams.get('dashboardTab') === 'advanced'),
            page.getByRole('tab', { name: 'Tableaux' }).click(),
        ]);
        await expect(page.locator('[data-dashboard-panel="advanced"]')).toBeVisible();

        await Promise.all([
            page.waitForURL(url => url.pathname === '/dashboard' && url.searchParams.get('dashboardTab') === 'charts'),
            page.getByRole('tab', { name: 'Graphiques' }).click(),
        ]);
        await expect(page.locator('[data-dashboard-panel="charts"]')).toBeVisible();

        await page.goto('/dashboard');
        const reportingCard = page.locator('a[data-dashboard-primary-kpi]').filter({ hasText: "Taux d'exécution" }).first();
        const actionsCard = page.locator('a[data-dashboard-primary-kpi]').filter({ hasText: 'Actions suivies' }).first();
        await expect(reportingCard).toHaveAttribute('href', /\/workspace\/reporting/);
        await expect(actionsCard).toHaveAttribute('href', /\/workspace\/actions/);
        await Promise.all([
            page.waitForURL(url => url.pathname === '/workspace/actions'),
            actionsCard.click(),
        ]);

        await page.goto('/workspace/actions');

        const actionHeader = page.locator('main .app-page-header').first();
        await expect(actionHeader).toBeVisible();
        if (page.viewportSize()!.width >= 1024) {
            expect(await actionHeader.evaluate(element => element.getBoundingClientRect().height)).toBeLessThan(150);
        }

        const advancedFilters = page.locator('[data-action-advanced-filters]');
        await expect(advancedFilters).not.toHaveAttribute('open', '');
        await advancedFilters.locator('summary').focus();
        await page.keyboard.press('Enter');
        await expect(advancedFilters).toHaveAttribute('open', '');

        await Promise.all([
            page.waitForURL(url => url.pathname === '/workspace/actions' && url.searchParams.get('financement_requis') === '0'),
            advancedFilters.locator('select[name="financement_requis"]').selectOption('0'),
        ]);
        await expect(page.locator('[data-action-advanced-filters]')).toHaveAttribute('open', '');
        await expect(page.locator('select[name="financement_requis"]')).toHaveValue('0');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/workspace/actions');
        const actionTable = page.locator('main table.app-table').first();
        await expect(actionTable).toBeVisible();
        const mobileTable = await actionTable.evaluate(table => {
            const wrapper = table.closest('.app-table-wrapper')!;
            const cells = Array.from(table.querySelectorAll('tbody td'));
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const firstCell = table.querySelector('tbody td');
            return {
                tableWidth: table.getBoundingClientRect().width,
                wrapperWidth: wrapper.getBoundingClientRect().width,
                clippedCells: cells.filter(cell => cell.scrollWidth > cell.clientWidth + 2).length,
                clippedRows: rows.filter(row => row.scrollWidth > row.clientWidth + 2).length,
                labelWhiteSpace: firstCell ? getComputedStyle(firstCell).whiteSpace : 'normal',
            };
        });
        expect(mobileTable.tableWidth).toBeLessThanOrEqual(mobileTable.wrapperWidth + 2);
        expect(mobileTable.clippedCells).toBe(0);
        expect(mobileTable.clippedRows).toBe(0);
        expect(mobileTable.labelWhiteSpace).not.toBe('nowrap');

        diagnostics.assertClean();
    });

    test('les accordéons progressifs ferment leurs frères et conservent les niveaux imbriqués', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'chromium');
        const diagnostics = observeBrowser(page, testInfo);

        await login(page, credentials.planning);
        await page.goto('/dashboard');
        await page.locator('main').evaluate(main => {
            main.insertAdjacentHTML('beforeend', [
                '<div id="e2e-progressive-group" data-progressive-accordion-group>',
                '<details data-progressive-accordion-item>',
                '<summary>Section A</summary>',
                '<div data-progressive-accordion-group>',
                '<details data-progressive-accordion-item><summary>Sous-section A1</summary></details>',
                '<details data-progressive-accordion-item><summary>Sous-section A2</summary></details>',
                '</div>',
                '</details>',
                '<details data-progressive-accordion-item><summary>Section B</summary></details>',
                '</div>',
            ].join(''));
            document.dispatchEvent(new CustomEvent('anbg:page-soft-refreshed'));
        });

        const group = page.locator('#e2e-progressive-group');
        const sections = group.locator(':scope > details');
        const nestedSections = sections.first().locator(':scope > div > details');

        await expect(sections.first()).toHaveAttribute('open', '');
        await expect(sections.nth(1)).not.toHaveAttribute('open', '');
        await expect(nestedSections.first()).toHaveAttribute('open', '');

        await nestedSections.nth(1).locator('summary').click();
        await expect(nestedSections.first()).not.toHaveAttribute('open', '');
        await expect(nestedSections.nth(1)).toHaveAttribute('open', '');
        await expect(sections.first()).toHaveAttribute('open', '');

        await sections.nth(1).locator('summary').focus();
        await page.keyboard.press('Enter');
        await expect(sections.first()).not.toHaveAttribute('open', '');
        await expect(sections.nth(1)).toHaveAttribute('open', '');

        diagnostics.assertClean();
    });
});
