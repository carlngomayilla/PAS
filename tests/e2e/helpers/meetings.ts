import { expect, Page } from '@playwright/test';
import { openMeetings } from './authentication';

const pdfBuffer = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n');

export function meetingName(kind: 'cycle' | 'correction' | 'téléversement', project: string): string {
    return `Réunion E2E ${kind} ${project}`;
}

async function submitFormAndFollowRedirect(page: Page, buttonName: string): Promise<void> {
    const button = page.getByRole('button', { name: buttonName });
    const action = await button.evaluate(element => (element as HTMLButtonElement).form?.action ?? '');
    expect(action, `Le formulaire « ${buttonName} » doit avoir une action.`).not.toBe('');

    const responsePromise = page.waitForResponse(response => (
        response.request().method() === 'POST'
        && response.url() === action
    ), { timeout: 60_000 });

    await button.click({ noWaitAfter: true });
    const response = await responsePromise;
    expect(response.status()).toBeGreaterThanOrEqual(300);
    expect(response.status()).toBeLessThan(400);

    const location = response.headers().location;
    expect(location, `Le formulaire « ${buttonName} » doit rediriger après le POST.`).toBeTruthy();
    await page.goto(new URL(location!, response.url()).toString(), {
        waitUntil: 'domcontentloaded',
        timeout: 60_000,
    });
}

export async function openMeeting(page: Page, title: string): Promise<void> {
    await openMeetings(page);
    const link = page.getByRole('link', { name: title, exact: true });
    if (page.context().browser()?.browserType().name() === 'firefox') {
        const href = await link.getAttribute('href');
        expect(href).not.toBeNull();
        await page.goto(href!);
    } else {
        await link.click({ noWaitAfter: true });
    }
    await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 60_000 });
}

export async function uploadValidReport(page: Page): Promise<void> {
    await page.locator('#report_file').setInputFiles({ name: 'pv-reunion-e2e.pdf', mimeType: 'application/pdf', buffer: pdfBuffer });
    await page.locator('#report_summary').fill('Synthèse complète du procès-verbal produite par le scénario E2E.');
    await page.locator('#actual_agenda').fill('Points de contrôle et suivi des décisions.');
    await page.locator('#report_decisions').fill('Décision de poursuivre le plan de travail validé.');
    await submitFormAndFollowRedirect(page, 'Chiffrer et transmettre au SCIQ');
    await expect(page.getByText('En validation SCIQ', { exact: true }).first()).toBeVisible({ timeout: 60_000 });
}

export async function reviewReport(page: Page, decision: 'VALIDATED' | 'CORRECTION_REQUESTED', comment = ''): Promise<void> {
    await page.locator('#review_decision').selectOption(decision);
    await page.locator('#review_comment').fill(comment);

    if (decision === 'CORRECTION_REQUESTED' && comment === '') {
        await page.getByRole('button', { name: 'Enregistrer le visa' }).click();

        return;
    }

    await submitFormAndFollowRedirect(page, 'Enregistrer le visa');
}

export async function scheduleFutureMeeting(page: Page, title: string): Promise<void> {
    await openMeetings(page);
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    await page.locator('#schedule_direction_id').selectOption({ label: 'E2E-DIR · Direction E2E principale' });
    await page.locator('#schedule_meeting_type').selectOption('service');
    await page.locator('#schedule_service_id').selectOption({ label: 'E2E-SRV · Service E2E principal' });
    await page.locator('#meeting_label').fill(title);
    await page.locator('#meeting_location').fill('Salle E2E 01');
    await page.locator('#meeting_responsible_id').selectOption({ label: 'Chef de service E2E' });
    await page.locator('#scheduled_date').fill(tomorrow.toISOString().slice(0, 10));
    await page.locator('#scheduled_time').fill('10:00');
    await page.locator('#meeting_agenda').fill('Revue des objectifs, décisions, risques et prochaines étapes.');
    await submitFormAndFollowRedirect(page, 'Programmer et notifier');
    await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 60_000 });
}
