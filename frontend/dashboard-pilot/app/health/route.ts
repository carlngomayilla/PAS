import { NextResponse } from 'next/server';
import { resolveLaravelOrigin } from '@/lib/laravel-origin.server';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

const LARAVEL_HEALTH_TIMEOUT_MS = 2_500;

function healthResponse(isReady: boolean): NextResponse {
    return NextResponse.json(
        {
            status: isReady ? 'ok' : 'unavailable',
            service: 'dashboard-pilot',
            schema_version: '1.0',
            dependency: 'laravel',
        },
        {
            status: isReady ? 200 : 503,
            headers: {
                'Cache-Control': 'no-store, max-age=0',
            },
        },
    );
}

export async function GET(): Promise<NextResponse> {
    try {
        const endpoint = new URL('/up', resolveLaravelOrigin());

        const response = await fetch(endpoint, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
            cache: 'no-store',
            redirect: 'error',
            signal: AbortSignal.timeout(LARAVEL_HEALTH_TIMEOUT_MS),
        });

        return healthResponse(response.ok);
    } catch {
        return healthResponse(false);
    }
}
