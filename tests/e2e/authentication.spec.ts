import { expect, test } from '@playwright/test';
import { login } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';
import { password } from './helpers/profiles';

test('un visiteur anonyme est redirigé vers la connexion', async ({ page }) => {
    await page.goto('/workspace');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('#loginId')).toBeVisible();
});

test('des identifiants invalides ne créent aucune session', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#loginId').fill('inconnu.e2e@example.test');
    await page.locator('#loginPwd').fill('Mot-de-passe-incorrect');
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.locator('#admin-sidebar')).toHaveCount(0);
});

test('un compte inactif reste bloqué', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium');
    await page.goto('/login');
    await page.locator('#loginId').fill('inactif.e2e@example.test');
    await page.locator('#loginPwd').fill(password);
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('alert')).toBeVisible();
});

test('le thème peut être changé sans erreur JavaScript', async ({ page }, testInfo) => {
    const diagnostics = observeBrowser(page, testInfo);
    await login(page, { email: 'agent.e2e@example.test', password });
    await expect(page.locator('#admin-theme-toggle')).toBeVisible();
    await page.locator('#admin-theme-toggle').click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    diagnostics.assertClean();
});

test('le menu principal possède un déclencheur accessible sur mobile', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-chrome');
    await login(page, { email: 'agent.e2e@example.test', password });
    const opener = page.locator('#admin-sidebar-open');
    const closer = page.locator('#admin-sidebar-close');
    const sidebar = page.locator('#admin-sidebar');
    const overlay = page.locator('#admin-overlay');

    await expect(opener).toBeVisible();
    await expect(opener).toHaveAttribute('aria-expanded', 'false');
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');

    await opener.click();
    await expect(page.getByRole('navigation', { name: 'Navigation principale' })).toBeVisible();
    await expect(opener).toHaveAttribute('aria-expanded', 'true');
    await expect(sidebar).toHaveAttribute('aria-hidden', 'false');
    await expect(closer).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(opener).toHaveAttribute('aria-expanded', 'false');
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
    await expect(opener).toBeFocused();

    await opener.click();
    const overlayBox = await overlay.boundingBox();
    expect(overlayBox).not.toBeNull();
    await overlay.click({
        position: {
            x: overlayBox!.width - 8,
            y: overlayBox!.height / 2,
        },
    });
    await expect(opener).toHaveAttribute('aria-expanded', 'false');
    await expect(opener).toBeFocused();

    await opener.click();
    await closer.click();
    await expect(opener).toHaveAttribute('aria-expanded', 'false');
    await expect(opener).toBeFocused();

    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(sidebar).toHaveAttribute('aria-hidden', 'false');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
    await expect(opener).toHaveAttribute('aria-expanded', 'false');
});
