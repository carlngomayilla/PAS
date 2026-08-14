import { expect, test } from '@playwright/test';
import { login, logout } from './helpers/authentication';
import { meetingName, openMeeting, reviewReport, uploadValidReport } from './helpers/meetings';
import { credentials } from './helpers/profiles';

test('une correction exige un motif et une nouvelle version du PV', async ({ page }, testInfo) => {
    test.setTimeout(480_000);
    const title = meetingName('correction', testInfo.project.name);
    await login(page, credentials.chief);
    await openMeeting(page, title);
    await uploadValidReport(page);
    await logout(page);

    await login(page, credentials.sciq);
    await openMeeting(page, title);
    await reviewReport(page, 'CORRECTION_REQUESTED');
    await expect(page.locator('.flash-error')).toContainText(/comment.*obligatoire/i);
    await expect(page.getByText('En validation SCIQ', { exact: true }).first()).toBeVisible();
    await reviewReport(page, 'CORRECTION_REQUESTED', 'La liste des participants doit être ajoutée au PV.');
    await expect(page.getByText('À corriger', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('La liste des participants doit être ajoutée au PV.').first()).toBeVisible();
    await logout(page);

    await login(page, credentials.chief);
    await openMeeting(page, title);
    await expect(page.getByText('Correction requise.')).toBeVisible();
    await uploadValidReport(page);
    await expect(page.getByText(/Version 2/)).toBeVisible();
});
