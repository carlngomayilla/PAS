import { expect, Page } from '@playwright/test';

export type Credentials = { email: string; password: string };

function requiresDirectSidebarNavigation(page: Page): boolean {
    return (page.viewportSize()?.width ?? 1280) < 768 || page.context().browser()?.browserType().name() === 'firefox';
}

export async function login(page: Page, credentials: Credentials): Promise<void> {
    await page.goto('/login');
    await page.locator('#loginId').fill(credentials.email);
    await page.locator('#loginPwd').fill(credentials.password);
    const loggedIn = page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 120_000, waitUntil: 'commit' });
    await page.getByRole('button', { name: 'Se connecter' }).click({ noWaitAfter: true });
    await loggedIn;
    await expect(page).not.toHaveURL(/\/login$/);
    await expect(page.locator('#admin-sidebar')).toBeAttached();
}

export async function logout(page: Page): Promise<void> {
    const logoutButton = page.locator('form[action$="/logout"] button[type="submit"]');
    const loggedOut = page.waitForURL(/\/login$/, { timeout: 120_000, waitUntil: 'commit' });
    if (requiresDirectSidebarNavigation(page)) {
        await page.locator('form[action$="/logout"]').evaluate((form: HTMLFormElement) => form.requestSubmit());
    } else if (await logoutButton.isVisible()) {
        await logoutButton.click({ noWaitAfter: true });
    } else {
        await page.locator('form[action$="/logout"]').evaluate((form: HTMLFormElement) => form.requestSubmit());
    }
    await loggedOut;
    await expect(page).toHaveURL(/\/login$/);
}

export async function openMeetings(page: Page): Promise<void> {
    if (requiresDirectSidebarNavigation(page)) {
        await page.goto('/workspace/reunions');
        await expect(page).toHaveURL(/\/workspace\/reunions\/?$/);

        return;
    }

    const link = page.getByRole('link', { name: 'Réunions & PV', exact: true });
    if (await link.isVisible()) {
        await link.scrollIntoViewIfNeeded();
        await expect(link).toHaveAttribute('href', /\/workspace\/reunions$/);
        await Promise.all([
            page.waitForURL(url => url.pathname === '/workspace/reunions', { timeout: 120_000, waitUntil: 'commit' }),
            link.click(),
        ]);
    } else {
        await page.goto('/workspace/reunions');
    }
    await expect(page).toHaveURL(/\/workspace\/reunions\/?$/);
}
