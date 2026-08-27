import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { dashboardFixture } from '@/tests/fixture';

const { headersMock } = vi.hoisted(() => ({
    headersMock: vi.fn(),
}));

vi.mock('next/headers', () => ({
    headers: headersMock,
}));

import { fetchDashboardOverview } from '@/lib/dashboard-api';

describe('dashboard server fetch', () => {
    beforeEach(() => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://127.0.0.1:8000');
        headersMock.mockResolvedValue(new Headers({
            cookie: 'laravel_session=session-value',
            authorization: 'Bearer test-token',
            host: 'pas.example.test',
            'x-forwarded-proto': 'https',
        }));
    });

    afterEach(() => {
        vi.unstubAllEnvs();
        vi.unstubAllGlobals();
    });

    it('forwards authentication, safe filters and no-store options', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify(dashboardFixture()),
            {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            },
        ));
        vi.stubGlobal('fetch', fetchMock);

        const result = await fetchDashboardOverview({
            exercice: '2026',
            periode: 'q2',
            statut_action: 'acheve',
            ignored: 'secret',
        });

        expect(result.ok).toBe(true);
        expect(fetchMock).toHaveBeenCalledOnce();

        const [url, options] = fetchMock.mock.calls[0] as [URL, RequestInit];
        expect(url.toString()).toBe(
            'http://127.0.0.1:8000/api/v1/dashboard/overview?exercice=2026&periode=q2&statut_action=acheve',
        );
        expect(options.cache).toBe('no-store');
        expect(options.credentials).toBe('include');
        expect(options.signal).toBeInstanceOf(AbortSignal);
        expect(new Headers(options.headers).get('cookie')).toBe('laravel_session=session-value');
        expect(new Headers(options.headers).get('authorization')).toBe('Bearer test-token');
        expect(new Headers(options.headers).get('origin')).toBe('https://pas.example.test');
        expect(new Headers(options.headers).get('referer'))
            .toBe('https://pas.example.test/dashboard-pilot');
    });

    it('uses the loopback Laravel fallback in development', async () => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', '');
        vi.stubEnv('NODE_ENV', 'development');
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify(dashboardFixture()),
            {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            },
        ));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchDashboardOverview({})).resolves.toMatchObject({ ok: true });

        const [url, options] = fetchMock.mock.calls[0] as [URL, RequestInit];
        expect(url.toString()).toBe('http://127.0.0.1:8000/api/v1/dashboard/overview');
        expect(new Headers(options.headers).get('cookie')).toBe('laravel_session=session-value');
        expect(new Headers(options.headers).get('authorization')).toBe('Bearer test-token');
    });

    it('fails closed in production when the Laravel origin is missing', async () => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', '');
        vi.stubEnv('NODE_ENV', 'production');
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchDashboardOverview({})).resolves.toMatchObject({
            ok: false,
            kind: 'unavailable',
            status: 0,
        });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it.each([
        'https://attacker.example',
        'http://169.254.169.254/latest/meta-data/',
        'http://localhost.attacker.example:8000',
        'http://user:password@127.0.0.1:8000',
        'http://127.0.0.1:8000/api',
        'http://127.0.0.1:8000?token=secret',
        'http://127.0.0.1:8000#fragment',
        'ftp://127.0.0.1:8000',
        'http://[::2]:8000',
    ])('rejects unsafe Laravel origin %s before forwarding credentials', async (origin) => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', origin);
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchDashboardOverview({})).resolves.toMatchObject({
            ok: false,
            kind: 'unavailable',
            status: 0,
        });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('maps expired and invalid-filter responses to explicit UI states', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response('{}', { status: 419 }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                errors: {
                    periode: ['La période est invalide.'],
                },
            }), {
                status: 422,
                headers: { 'Content-Type': 'application/json' },
            })));

        await expect(fetchDashboardOverview({})).resolves.toMatchObject({
            ok: false,
            kind: 'expired',
            status: 419,
        });
        await expect(fetchDashboardOverview({ periode: 'q5' })).resolves.toMatchObject({
            ok: false,
            kind: 'validation',
            status: 422,
            errors: ['La période est invalide.'],
        });
    });

    it('returns an unavailable state when Laravel cannot be reached', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network unavailable')));

        await expect(fetchDashboardOverview({})).resolves.toMatchObject({
            ok: false,
            kind: 'unavailable',
            status: 0,
        });
    });
});
