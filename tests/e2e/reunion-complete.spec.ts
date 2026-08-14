import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { login, logout, openMeetings } from './helpers/authentication';
import { observeBrowser } from './helpers/browser-errors';
import { meetingName, openMeeting, reviewReport, scheduleFutureMeeting, uploadValidReport } from './helpers/meetings';
import { credentials } from './helpers/profiles';

test('le cycle Réunion/PV complet est réalisé par les profils successifs', async ({ page }, testInfo) => {
    test.setTimeout(900_000);
    const diagnostics = observeBrowser(page, testInfo);
    const elapsedMeeting = meetingName('cycle', testInfo.project.name);
    await login(page, credentials.sciq);
    await openMeetings(page);
    const currentDate = new Date();
    await page.locator('#plan_direction_id').selectOption({ label: 'E2E-DIR · Direction E2E principale' });
    await page.locator('#plan_meeting_type').selectOption('service');
    await page.locator('#plan_service_id').selectOption({ label: 'E2E-SRV · Service E2E principal' });
    await page.locator('#plan_year').fill(String(currentDate.getFullYear()));
    await page.locator('#plan_month').selectOption(String(currentDate.getMonth() + 1));
    await page.locator('#expected_count').fill('4');
    await page.getByRole('button', { name: 'Publier l’objectif' }).click();
    await expect(page.locator('.dashboard-primary-kpi-card').filter({ hasText: 'Objectif' }).getByText('4', { exact: true })).toBeVisible();
    await logout(page);

    await login(page, credentials.chief);
    await scheduleFutureMeeting(page, `Réunion E2E programmée ${testInfo.project.name}`);
    await openMeeting(page, elapsedMeeting);
    await uploadValidReport(page);
    await logout(page);

    await login(page, credentials.sciq);
    await openMeeting(page, elapsedMeeting);
    await reviewReport(page, 'VALIDATED', 'PV conforme après contrôle SCIQ.');
    await expect(page.getByText('En validation Planification', { exact: true }).first()).toBeVisible();
    await logout(page);

    await login(page, credentials.planning);
    await openMeeting(page, elapsedMeeting);
    await reviewReport(page, 'VALIDATED', 'Validation finale du service Planification.');
    await expect(page.getByText('Validée définitivement', { exact: true }).first()).toBeVisible();
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Télécharger le PV actif' }).click();
    const download = await downloadPromise;
    const savedPath = testInfo.outputPath(download.suggestedFilename());
    await download.saveAs(savedPath);
    expect((await readFile(savedPath)).subarray(0, 4).toString()).toBe('%PDF');
    await openMeetings(page);
    await expect(page.getByText('Validées', { exact: true })).toBeVisible();
    diagnostics.assertClean();
});
