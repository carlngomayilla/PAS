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
