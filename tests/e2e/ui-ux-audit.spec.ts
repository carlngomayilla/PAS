import { expect, Page, test, TestInfo } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { password, profiles, TestProfile } from './helpers/profiles';
import { auditRenderedPage, UiAuditIssue } from './helpers/ui-audit';

type AuditedIssue = UiAuditIssue & {
    profile: string;
    page: string;
    theme: 'light' | 'dark';
    viewport: string;
};

type ModuleLink = { href: string; label: string };

async function loginForAudit(page: Page, profile: TestProfile): Promise<boolean> {
    await page.goto('/login');
    await page.locator('#loginId').fill(profile.email);
    await page.locator('#loginPwd').fill(password);
    await page.getByRole('button', { name: 'Se connecter' }).click({ noWaitAfter: true });
    await page.waitForURL(url => ! url.pathname.endsWith('/login'), { timeout: 120_000, waitUntil: 'commit' });

    return (await page.locator('#admin-sidebar').count()) > 0;
}

async function visibleModules(page: Page): Promise<ModuleLink[]> {
    return page.locator('#admin-sidebar a.app-sidebar-link').evaluateAll((anchors): ModuleLink[] => anchors.map(anchor => ({
        href: (anchor as HTMLAnchorElement).href,
        label: (anchor.textContent ?? '').trim().replace(/\s+/g, ' '),
    })));
}

async function auditModule(
    page: Page,
    profile: TestProfile,
    module: ModuleLink,
    viewport: string,
    themes: Array<'light' | 'dark'>,
): Promise<AuditedIssue[]> {
    const response = await page.goto(module.href, { waitUntil: 'domcontentloaded', timeout: 120_000 });
    const issues: AuditedIssue[] = [];

    if ((response?.status() ?? 0) >= 400) {
        issues.push({
            category: 'http-status',
            severity: response?.status() === 403 ? 'high' : 'medium',
            selector: 'document',
            detail: `La page visible dans le menu répond HTTP ${response?.status() ?? 'inconnu'}.`,
            profile: profile.code,
            page: module.label,
            theme: 'light',
            viewport,
        });

        return issues;
    }

    for (const theme of themes) {
        await page.evaluate(selectedTheme => {
            document.documentElement.classList.toggle('dark', selectedTheme === 'dark');
            document.documentElement.setAttribute('data-theme', selectedTheme);
        }, theme);
        await page.waitForTimeout(1000);

        const findings = await auditRenderedPage(page);
        issues.push(...findings.map(issue => ({
            ...issue,
            profile: profile.code,
            page: module.label,
            theme,
            viewport,
        })));
    }

    return issues;
}

async function attachAudit(testInfo: TestInfo, name: string, issues: AuditedIssue[], pageCount: number): Promise<void> {
    const counts = issues.reduce<Record<string, number>>((carry, issue) => {
        const key = `${issue.severity}:${issue.category}`;
        carry[key] = (carry[key] ?? 0) + 1;

        return carry;
    }, {});

    const report = JSON.stringify({ pageCount, issueCount: issues.length, counts, issues }, null, 2);
    await testInfo.attach(`${name}.json`, {
        body: Buffer.from(report),
        contentType: 'application/json',
    });

    const reportDirectory = path.resolve('output/ui-ux-audit');
    await mkdir(reportDirectory, { recursive: true });
    await writeFile(path.join(reportDirectory, `${name}.json`), report, 'utf8');

    console.log(`UI_UX_AUDIT ${name} pages=${pageCount} issues=${issues.length} ${JSON.stringify(counts)}`);
}

function assertAuditClean(issues: AuditedIssue[]): void {
    expect(issues, `Anomalies UI/UX détectées :\n${JSON.stringify(issues.slice(0, 25), null, 2)}`).toEqual([]);
}

for (const profile of profiles) {
    test(`audit UI/UX bureau complet — ${profile.label}`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'chromium');
        test.setTimeout(900_000);
        await page.setViewportSize({ width: 1440, height: 900 });
        const viewport = '1440x900';
        const canUseWorkspace = await loginForAudit(page, profile);
        const issues: AuditedIssue[] = [];

        if (! canUseWorkspace) {
            issues.push({
                category: 'workspace-blocked',
                severity: 'high',
                selector: 'document',
                detail: `Le profil termine sur ${page.url()} sans espace de travail visible.`,
                profile: profile.code,
                page: 'connexion',
                theme: 'light',
                viewport,
            });
            await attachAudit(testInfo, `desktop-${profile.code}`, issues, 1);
            assertAuditClean(issues);

            return;
        }

        const modules = await visibleModules(page);
        expect(modules.length, `Aucun module à auditer pour ${profile.code}`).toBeGreaterThan(0);
        for (const module of modules) {
            issues.push(...await auditModule(page, profile, module, viewport, ['light', 'dark']));
        }

        await attachAudit(testInfo, `desktop-${profile.code}`, issues, modules.length * 2);
        assertAuditClean(issues);
    });
}

const responsiveProfiles: Array<{ code: string; width: number; height: number }> = [
    { code: 'super_admin', width: 390, height: 844 },
    { code: 'service', width: 390, height: 844 },
    { code: 'agent', width: 320, height: 568 },
    { code: 'direction', width: 768, height: 1024 },
];

for (const responsiveProfile of responsiveProfiles) {
    const profile = profiles.find(candidate => candidate.code === responsiveProfile.code)!;
    test(`audit UI/UX responsive ${responsiveProfile.width}x${responsiveProfile.height} — ${profile.label}`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'mobile-chrome');
        test.setTimeout(900_000);
        await page.setViewportSize({ width: responsiveProfile.width, height: responsiveProfile.height });
        const viewport = `${responsiveProfile.width}x${responsiveProfile.height}`;
        const canUseWorkspace = await loginForAudit(page, profile);
        const issues: AuditedIssue[] = [];

        if (! canUseWorkspace) {
            issues.push({
                category: 'workspace-blocked',
                severity: 'high',
                selector: 'document',
                detail: `Le profil termine sur ${page.url()} sans espace de travail visible.`,
                profile: profile.code,
                page: 'connexion',
                theme: 'light',
                viewport,
            });
            await attachAudit(testInfo, `responsive-${profile.code}-${viewport}`, issues, 1);
            assertAuditClean(issues);

            return;
        }

        const modules = await visibleModules(page);
        const menuOpenerCount = await page.locator('#admin-sidebar-open').count();
        if (menuOpenerCount === 0) {
            issues.push({
                category: 'mobile-menu-opener',
                severity: 'high',
                selector: '#admin-sidebar-open',
                detail: 'Aucun déclencheur ne permet d’ouvrir la navigation latérale.',
                profile: profile.code,
                page: 'navigation',
                theme: 'light',
                viewport,
            });
        }

        for (const module of modules) {
            issues.push(...await auditModule(page, profile, module, viewport, ['light', 'dark']));
        }

        await attachAudit(testInfo, `responsive-${profile.code}-${viewport}`, issues, modules.length * 2);
        assertAuditClean(issues);
    });
}

test('audit UI/UX de la connexion publique', async ({ page }, testInfo) => {
    test.setTimeout(180_000);
    const issues: AuditedIssue[] = [];

    for (const viewport of [
        { width: 1440, height: 900 },
        { width: 390, height: 844 },
        { width: 320, height: 568 },
    ]) {
        await page.setViewportSize(viewport);
        await page.goto('/login', { waitUntil: 'domcontentloaded' });
        const findings = await auditRenderedPage(page);
        issues.push(...findings.map(issue => ({
            ...issue,
            profile: 'anonymous',
            page: 'Connexion',
            theme: 'light' as const,
            viewport: `${viewport.width}x${viewport.height}`,
        })));
    }

    await attachAudit(testInfo, 'public-login', issues, 3);
    assertAuditClean(issues);
});

test('audit UI/UX Firefox représentatif — Super Admin', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'firefox');
    test.setTimeout(900_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    const profile = profiles.find(candidate => candidate.code === 'super_admin')!;
    const canUseWorkspace = await loginForAudit(page, profile);
    const issues: AuditedIssue[] = [];

    if (! canUseWorkspace) {
        issues.push({
            category: 'workspace-blocked',
            severity: 'high',
            selector: 'document',
            detail: `Le profil termine sur ${page.url()} sans espace de travail visible.`,
            profile: profile.code,
            page: 'connexion',
            theme: 'light',
            viewport: '1440x900',
        });
        await attachAudit(testInfo, 'firefox-super-admin', issues, 1);
        assertAuditClean(issues);

        return;
    }

    const modules = await visibleModules(page);
    for (const module of modules) {
        issues.push(...await auditModule(page, profile, module, '1440x900', ['light']));
    }

    await attachAudit(testInfo, 'firefox-super-admin', issues, modules.length);
    assertAuditClean(issues);
});
