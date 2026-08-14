import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';
import { password, profiles } from './helpers/profiles';

type ModuleLink = { href: string; label: string };

for (const profile of profiles) {
    test(`${profile.label} ouvre tous les modules visibles de son profil`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'chromium');
        test.setTimeout(300_000);
        const diagnostics = observeBrowser(page, testInfo);
        await login(page, { email: profile.email, password });
        const modules = await page.locator('#admin-sidebar a.app-sidebar-link').evaluateAll((anchors): ModuleLink[] => anchors.map(anchor => ({
            href: (anchor as HTMLAnchorElement).href,
            label: (anchor.textContent ?? '').trim().replace(/\s+/g, ' '),
        })));
        expect(modules.length, `Aucun module pour ${profile.code}`).toBeGreaterThan(1);
        const results: Array<ModuleLink & { status: number | null; finalUrl: string }> = [];

        for (const module of modules) {
            const response = await page.goto(module.href, { waitUntil: 'domcontentloaded' });
            results.push({ ...module, status: response?.status() ?? null, finalUrl: page.url() });
            expect.soft(response?.status(), `${profile.label} → ${module.label}`).toBeLessThan(500);
            expect.soft(response?.status(), `Module visible mais interdit : ${profile.label} → ${module.label}`).not.toBe(403);
            expect.soft(page.url()).not.toMatch(/\/login$/);
        }

        await testInfo.attach(`navigation-${profile.code}.json`, {
            body: Buffer.from(JSON.stringify(results, null, 2)),
            contentType: 'application/json',
        });
        diagnostics.assertClean();
    });
}
