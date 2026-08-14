import { expect, test } from '@playwright/test';
import { login, logout, openMeetings } from './helpers/authentication';
import { openMeeting } from './helpers/meetings';
import { credentials } from './helpers/profiles';

test('le PV d’un autre service est protégé dans la liste, la fiche et le téléchargement', async ({ page }) => {
    test.setTimeout(360_000);
    const title = 'Réunion E2E protégée autre service';
    await login(page, credentials.chief);
    await openMeeting(page, title);
    const showUrl = page.url();
    const downloadUrl = await page.getByRole('link', { name: 'Télécharger le PV actif' }).getAttribute('href');
    expect(downloadUrl).not.toBeNull();
    await expect(page.locator('#review_decision')).toHaveCount(0);
    await logout(page);

    await login(page, credentials.outsideAgent);
    await openMeetings(page);
    await expect(page.getByRole('link', { name: title, exact: true })).toHaveCount(0);
    expect((await page.goto(showUrl))?.status()).toBe(403);
    expect((await page.goto(downloadUrl!))?.status()).toBe(403);
});

test('un directeur du périmètre peut consulter et télécharger le PV', async ({ page }) => {
    await login(page, credentials.director);
    await openMeeting(page, 'Réunion E2E protégée autre service');
    await expect(page.getByRole('link', { name: 'Télécharger le PV actif' })).toBeVisible();
});

test('un faux PDF est rejeté sans créer de version', async ({ page }, testInfo) => {
    await login(page, credentials.chief);
    await openMeeting(page, `Réunion E2E téléversement ${testInfo.project.name}`);
    await page.locator('#report_file').setInputFiles({
        name: 'faux-pv.pdf',
        mimeType: 'text/plain',
        buffer: Buffer.from('ce fichier texte se fait passer pour un PDF'),
    });
    await page.locator('#report_summary').fill('Synthèse suffisamment longue pour isoler le contrôle du type de fichier.');
    await page.getByRole('button', { name: 'Chiffrer et transmettre au SCIQ' }).click();
    await expect(page.locator('.flash-error').filter({ hasText: 'Le champ report doit être un fichier de type' })).toContainText('Le champ report doit être un fichier de type');
    await expect(page.getByText('Aucun PV déposé.')).toBeVisible();
});
