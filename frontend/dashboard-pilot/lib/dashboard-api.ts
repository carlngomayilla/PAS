import { headers } from 'next/headers';
import {
    type DashboardOverviewPayload,
    unwrapDashboardPayload,
} from '@/lib/dashboard-contract';
import { resolveLaravelOrigin } from '@/lib/laravel-origin.server';

const ALLOWED_QUERY_PARAMETERS = [
    'exercice',
    'periode',
    'direction_id',
    'service_id',
    'statut_suivi',
    'statut_delai',
    'alerte_echeance',
    'responsable_id',
    'statut_action',
] as const;
const DASHBOARD_REQUEST_TIMEOUT_MS = 10_000;

type SearchParameters = Record<string, string | string[] | undefined>;

export type DashboardFetchResult =
    | {
        ok: true;
        data: DashboardOverviewPayload;
    }
    | {
        ok: false;
        kind: 'unauthenticated' | 'forbidden' | 'expired' | 'validation' | 'unavailable' | 'invalid-response';
        status: number;
        message: string;
        errors: string[];
    };

function firstHeaderValue(value: string | null): string {
    return value?.split(',')[0]?.trim() ?? '';
}

function forwardedOrigin(requestHeaders: Headers): string | null {
    const host = firstHeaderValue(requestHeaders.get('x-forwarded-host'))
        || firstHeaderValue(requestHeaders.get('host'));
    const protocol = firstHeaderValue(requestHeaders.get('x-forwarded-proto')) || 'http';
    const validHostname = /^[a-z0-9.-]+(?::\d{1,5})?$/i.test(host)
        || /^\[[0-9a-f:]+\](?::\d{1,5})?$/i.test(host);

    if (!validHostname || !['http', 'https'].includes(protocol)) {
        return null;
    }

    return `${protocol}://${host}`;
}

function appendSearchParameters(url: URL, parameters: SearchParameters): void {
    for (const parameter of ALLOWED_QUERY_PARAMETERS) {
        const value = parameters[parameter];

        if (typeof value === 'string' && value.trim() !== '') {
            url.searchParams.set(parameter, value.trim());
        }
    }
}

async function responseBody(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function validationMessages(value: unknown): string[] {
    if (typeof value !== 'object' || value === null || !('errors' in value)) {
        return [];
    }

    const errors = (value as { errors?: unknown }).errors;

    if (typeof errors !== 'object' || errors === null || Array.isArray(errors)) {
        return [];
    }

    return Object.values(errors)
        .flatMap((messages) => Array.isArray(messages) ? messages : [])
        .filter((message): message is string => typeof message === 'string')
        .slice(0, 8);
}

function failure(
    kind: Exclude<DashboardFetchResult, { ok: true }>['kind'],
    status: number,
    message: string,
    errors: string[] = [],
): DashboardFetchResult {
    return { ok: false, kind, status, message, errors };
}

export async function fetchDashboardOverview(
    parameters: SearchParameters,
): Promise<DashboardFetchResult> {
    try {
        const laravelOrigin = resolveLaravelOrigin();
        const requestHeaders = await headers();
        const endpoint = new URL('/api/v1/dashboard/overview', laravelOrigin);
        const statefulOrigin = forwardedOrigin(requestHeaders);
        const cookie = requestHeaders.get('cookie');
        const authorization = requestHeaders.get('authorization');
        const forwardedHeaders = new Headers({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        });

        appendSearchParameters(endpoint, parameters);

        if (cookie) {
            forwardedHeaders.set('Cookie', cookie);
        }

        if (authorization) {
            forwardedHeaders.set('Authorization', authorization);
        }

        if (statefulOrigin) {
            forwardedHeaders.set('Origin', statefulOrigin);
            forwardedHeaders.set('Referer', `${statefulOrigin}/dashboard-pilot`);
        }

        const response = await fetch(endpoint, {
            method: 'GET',
            headers: forwardedHeaders,
            credentials: 'include',
            cache: 'no-store',
            redirect: 'manual',
            signal: AbortSignal.timeout(DASHBOARD_REQUEST_TIMEOUT_MS),
        });
        const body = await responseBody(response);

        if (response.status === 401) {
            return failure('unauthenticated', 401, 'Votre session est requise pour afficher ce tableau de bord.');
        }

        if (response.status === 403) {
            return failure('forbidden', 403, 'Votre profil ne dispose pas de cet accès.');
        }

        if (response.status === 419) {
            return failure('expired', 419, 'Votre session a expiré. Reconnectez-vous pour continuer.');
        }

        if (response.status === 422) {
            return failure(
                'validation',
                422,
                'Un ou plusieurs filtres ne sont pas valides.',
                validationMessages(body),
            );
        }

        if (!response.ok) {
            return failure(
                response.status >= 500 ? 'unavailable' : 'invalid-response',
                response.status,
                'Le tableau de bord est temporairement indisponible.',
            );
        }

        const payload = unwrapDashboardPayload(body);

        if (!payload) {
            return failure(
                'invalid-response',
                response.status,
                'La réponse du tableau de bord ne respecte pas le contrat attendu.',
            );
        }

        return { ok: true, data: payload };
    } catch (error) {
        const failureReason = error instanceof Error ? error.name : 'UnknownError';

        if (process.env.NODE_ENV !== 'test') {
            console.error('Dashboard overview request failed.', {
                reason: failureReason,
            });
        }

        return failure(
            'unavailable',
            0,
            ['AbortError', 'TimeoutError'].includes(failureReason)
                ? 'Le service du tableau de bord a dépassé le délai de réponse.'
                : 'Impossible de joindre le service du tableau de bord.',
        );
    }
}
