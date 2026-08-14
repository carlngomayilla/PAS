import { expect, test } from '@playwright/test';
import { login, openMeetings } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';
import { scheduleFutureMeeting } from './helpers/meetings';
import { credentials } from './helpers/profiles';

test('la fiche réunion est utilisable au clavier sans erreur JavaScript', async ({ page }, testInfo) => {
    const diagnostics = observeBrowser(page, testInfo);
    await login(page, credentials.chief);
    await openMeetings(page);
    const link = page.getByRole('link', { name: 'Réunion E2E interface', exact: true });
    await link.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { name: 'Réunion E2E interface' })).toBeVisible();
    diagnostics.assertClean();
});

test('une réunion future peut être reportée puis annulée avec un motif', async ({ page }, testInfo) => {
    const title = `Réunion E2E calendrier ${testInfo.project.name}`;
    await login(page, credentials.chief);
    await scheduleFutureMeeting(page, title);
    const later = new Date();
    later.setDate(later.getDate() + 2);
    await page.locator('#postponed_date').fill(later.toISOString().slice(0, 10));
    await page.locator('#postponed_time').fill('14:30');
    await page.locator('#postponement_reason').fill('Indisponibilité collective confirmée pour la date initiale.');
    await page.getByRole('button', { name: 'Reporter et notifier' }).click();
    await expect(page.getByText('Reportée', { exact: true }).first()).toBeVisible();
    await page.locator('#cancellation_reason').fill('Annulation décidée après modification des priorités du service.');
    await page.getByRole('button', { name: 'Annuler et notifier' }).click();
    await expect(page.getByText('Annulée', { exact: true }).first()).toBeVisible();
});
