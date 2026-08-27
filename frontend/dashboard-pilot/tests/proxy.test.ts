import { NextRequest } from 'next/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { proxy } from '@/proxy';

describe('dashboard CSP proxy', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('creates a fresh strict nonce policy and security headers per request', () => {
        vi.stubEnv('NODE_ENV', 'production');

        const first = proxy(new NextRequest('https://example.test/dashboard-pilot'));
        const second = proxy(new NextRequest('https://example.test/dashboard-pilot'));
        const firstPolicy = first.headers.get('Content-Security-Policy') ?? '';
        const secondPolicy = second.headers.get('Content-Security-Policy') ?? '';
        const firstNonce = firstPolicy.match(/'nonce-([^']+)'/)?.[1];
        const secondNonce = secondPolicy.match(/'nonce-([^']+)'/)?.[1];

        expect(firstNonce).toBeTruthy();
        expect(secondNonce).toBeTruthy();
        expect(firstNonce).not.toBe(secondNonce);
        expect(firstPolicy).toContain("script-src 'self'");
        expect(firstPolicy).toContain("'strict-dynamic'");
        expect(firstPolicy).toContain("frame-ancestors 'none'");
        expect(firstPolicy).toContain('upgrade-insecure-requests');
        expect(first.headers.get('X-Content-Type-Options')).toBe('nosniff');
        expect(first.headers.get('Permissions-Policy')).toContain('camera=()');
    });

    it('allows the development runtime requirements without upgrading localhost traffic', () => {
        vi.stubEnv('NODE_ENV', 'development');

        const response = proxy(new NextRequest('http://localhost:3000/dashboard-pilot'));
        const policy = response.headers.get('Content-Security-Policy') ?? '';

        expect(policy).toContain("'unsafe-eval'");
        expect(policy).toContain('ws: wss:');
        expect(policy).not.toContain('upgrade-insecure-requests');
    });
});
