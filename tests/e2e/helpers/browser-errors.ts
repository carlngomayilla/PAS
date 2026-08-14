import { expect, Page, TestInfo } from '@playwright/test';

export function observeBrowser(page: Page, testInfo: TestInfo): { assertClean: () => void } {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    page.on('console', message => { if (message.type() === 'error') errors.push(`console: ${message.text()}`); });
    page.on('requestfailed', request => {
        const failureText = request.failure()?.errorText ?? '';
        const expectedDownloadAbort = failureText.includes('ERR_ABORTED')
            && /\/telecharger(?:\?|$)/.test(request.url());
        const expectedNavigationAbort = request.method() === 'GET'
            && (failureText.includes('ERR_ABORTED') || failureText.includes('NS_BINDING_ABORTED'));
        if (request.url().startsWith('http://127.0.0.1') && ! expectedDownloadAbort && ! expectedNavigationAbort) {
            errors.push(`requestfailed: ${request.method()} ${request.url()} (${failureText})`);
        }
    });
    page.on('response', response => {
        if (response.url().startsWith('http://127.0.0.1') && response.status() >= 500) errors.push(`response: ${response.status()} ${response.url()}`);
    });
    return { assertClean: () => expect(errors, `Anomalies navigateur [${testInfo.project.name}]`).toEqual([]) };
}
