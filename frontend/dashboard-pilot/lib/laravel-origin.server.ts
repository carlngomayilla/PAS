import { isIP } from 'node:net';

const DEVELOPMENT_LARAVEL_ORIGIN = 'http://127.0.0.1:8000';

function hostnameWithoutBrackets(hostname: string): string {
    return hostname.startsWith('[') && hostname.endsWith(']')
        ? hostname.slice(1, -1)
        : hostname;
}

function isLoopbackHostname(hostname: string): boolean {
    const normalizedHostname = hostnameWithoutBrackets(hostname).toLowerCase();

    if (normalizedHostname === 'localhost' || normalizedHostname === '::1') {
        return true;
    }

    return isIP(normalizedHostname) === 4 && normalizedHostname.startsWith('127.');
}

export function validatedLaravelOrigin(value: string): string {
    const url = new URL(value);

    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('LARAVEL_INTERNAL_URL doit utiliser HTTP ou HTTPS.');
    }

    if (url.username !== '' || url.password !== '') {
        throw new Error('LARAVEL_INTERNAL_URL ne doit pas contenir d’identifiants.');
    }

    if (url.pathname !== '/' || url.search !== '' || url.hash !== '') {
        throw new Error('LARAVEL_INTERNAL_URL doit contenir uniquement une origine.');
    }

    if (!isLoopbackHostname(url.hostname)) {
        throw new Error('LARAVEL_INTERNAL_URL doit cibler une adresse loopback.');
    }

    return url.origin;
}

export function resolveLaravelOrigin(): string {
    const configuredOrigin = process.env.LARAVEL_INTERNAL_URL?.trim();

    if (configuredOrigin) {
        return validatedLaravelOrigin(configuredOrigin);
    }

    if (process.env.NODE_ENV === 'production') {
        throw new Error('LARAVEL_INTERNAL_URL est requis en production.');
    }

    return DEVELOPMENT_LARAVEL_ORIGIN;
}
