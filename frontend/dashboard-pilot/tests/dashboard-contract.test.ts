import { describe, expect, it } from 'vitest';
import {
    safeRelativeLink,
    type DashboardOverviewPayload,
    unwrapDashboardPayload,
} from '@/lib/dashboard-contract';
import { dashboardFixture } from '@/tests/fixture';

describe('dashboard API contract', () => {
    it('accepts both the flat Laravel response and a resource envelope', () => {
        const payload = dashboardFixture();

        expect(unwrapDashboardPayload(payload)).toEqual(payload);
        expect(unwrapDashboardPayload({ data: payload })).toEqual(payload);
    });

    it('rejects incomplete or unsupported payloads', () => {
        expect(unwrapDashboardPayload(null)).toBeNull();
        expect(unwrapDashboardPayload({ schema_version: '2.0' })).toBeNull();
        expect(unwrapDashboardPayload({ data: { schema_version: '1.0' } })).toBeNull();

        const invalidTotals = structuredClone(dashboardFixture()) as unknown as {
            metrics: { totals: { actions_total: unknown } };
        };
        invalidTotals.metrics.totals.actions_total = '20';
        expect(unwrapDashboardPayload(invalidTotals)).toBeNull();

        const fractionalCounterMutations: Array<(payload: DashboardOverviewPayload) => void> = [
            (payload) => { payload.metrics.totals.actions_total = 1.5; },
            (payload) => { payload.metrics.alerts.actions_en_retard = 1.5; },
            (payload) => { payload.metrics.status_breakdown.actions.en_cours = 1.5; },
            (payload) => { payload.metrics.action_scope.visible_actions_total = 1.5; },
            (payload) => { payload.synthesis_decision_summary.total = 1.5; },
            (payload) => { payload.synthesis_decision_summary.workflow.en_cours = 1.5; },
            (payload) => {
                if (payload.financial_summary) {
                    payload.financial_summary.actions_total = 1.5;
                }
            },
        ];
        for (const mutate of fractionalCounterMutations) {
            const fractionalCounter = structuredClone(dashboardFixture());
            mutate(fractionalCounter);
            expect(unwrapDashboardPayload(fractionalCounter)).toBeNull();
        }

        const invalidOptions = structuredClone(dashboardFixture()) as unknown as {
            direction_selector: { options: unknown };
        };
        invalidOptions.direction_selector.options = [{ id: 'direction-1', label: 'Direction' }];
        expect(unwrapDashboardPayload(invalidOptions)).toBeNull();

        const invalidBreakdown = structuredClone(dashboardFixture()) as unknown as {
            links: { breakdowns: { actions: Record<string, unknown> } };
        };
        invalidBreakdown.links.breakdowns.actions.en_cours = { href: '/workspace/actions' };
        expect(unwrapDashboardPayload(invalidBreakdown)).toBeNull();

        const unsupportedBreakdown = structuredClone(dashboardFixture()) as unknown as {
            links: { breakdowns: Record<string, unknown> };
        };
        unsupportedBreakdown.links.breakdowns.external = {};
        expect(unwrapDashboardPayload(unsupportedBreakdown)).toBeNull();

        const missingBreakdowns = structuredClone(dashboardFixture()) as unknown as {
            links: { breakdowns?: unknown };
        };
        delete missingBreakdowns.links.breakdowns;
        expect(unwrapDashboardPayload(missingBreakdowns)).toBeNull();

        const missingBreakdownGroup = structuredClone(dashboardFixture()) as unknown as {
            links: { breakdowns: { alerts?: unknown } };
        };
        delete missingBreakdownGroup.links.breakdowns.alerts;
        expect(unwrapDashboardPayload(missingBreakdownGroup)).toBeNull();

        for (const link of ['pas', 'paos', 'ptas', 'late_actions', 'kpi_below_threshold']) {
            const missingRequiredLink = structuredClone(dashboardFixture()) as unknown as {
                links: Record<string, unknown>;
            };
            delete missingRequiredLink.links[link];
            expect(unwrapDashboardPayload(missingRequiredLink)).toBeNull();
        }

        const unsupportedLink = structuredClone(dashboardFixture()) as unknown as {
            links: Record<string, unknown>;
        };
        unsupportedLink.links.external = '/external';
        expect(unwrapDashboardPayload(unsupportedLink)).toBeNull();
    });

    it('only keeps safe relative links', () => {
        expect(safeRelativeLink('/workspace/actions', '/fallback')).toBe('/workspace/actions');
        expect(safeRelativeLink('//malicious.example', '/fallback')).toBe('/fallback');
        expect(safeRelativeLink('/unsafe\\path', '/fallback')).toBe('/fallback');
        expect(safeRelativeLink('https://malicious.example', '/fallback')).toBe('/fallback');
    });
});
