import {
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import { GET } from '@/app/health/route';

describe('dashboard health route', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
        vi.unstubAllGlobals();
    });

    it('returns a deterministic no-store readiness response when Laravel is healthy', async () => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://127.0.0.1:8000');
        const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const response = await GET();

        expect(response.status).toBe(200);
        expect(response.headers.get('Cache-Control')).toContain('no-store');
        expect(fetchMock).toHaveBeenCalledWith(
            new URL('http://127.0.0.1:8000/up'),
            expect.objectContaining({
                cache: 'no-store',
                redirect: 'error',
            }),
        );
        await expect(response.json()).resolves.toEqual({
            status: 'ok',
            service: 'dashboard-pilot',
            schema_version: '1.0',
            dependency: 'laravel',
        });
    });

    it('uses the same loopback fallback in development', async () => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', '');
        vi.stubEnv('NODE_ENV', 'development');
        const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const response = await GET();

        expect(response.status).toBe(200);
        expect(fetchMock).toHaveBeenCalledWith(
            new URL('http://127.0.0.1:8000/up'),
            expect.objectContaining({
                cache: 'no-store',
                redirect: 'error',
            }),
        );
    });

    it.each([
        ['missing production configuration', '', null],
        ['invalid protocol', 'file:///tmp/laravel', null],
        ['non-loopback host', 'http://laravel.internal', null],
        ['credentials', 'http://user:password@127.0.0.1:8000', null],
        ['path', 'http://127.0.0.1:8000/api', null],
        ['query', 'http://127.0.0.1:8000?token=secret', null],
        ['fragment', 'http://127.0.0.1:8000#fragment', null],
        ['unhealthy Laravel', 'http://127.0.0.1:8000', 500],
    ] as const)('returns 503 for %s', async (_case, origin, upstreamStatus) => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', origin);
        vi.stubEnv('NODE_ENV', 'production');
        const fetchMock = vi.fn();

        if (upstreamStatus !== null) {
            fetchMock.mockResolvedValue(new Response(null, { status: upstreamStatus }));
        }
        vi.stubGlobal('fetch', fetchMock);

        const response = await GET();

        expect(response.status).toBe(503);
        if (upstreamStatus === null) {
            expect(fetchMock).not.toHaveBeenCalled();
        }
        await expect(response.json()).resolves.toEqual({
            status: 'unavailable',
            service: 'dashboard-pilot',
            schema_version: '1.0',
            dependency: 'laravel',
        });
    });
});
