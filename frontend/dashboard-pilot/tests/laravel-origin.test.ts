import {
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import {
    resolveLaravelOrigin,
    validatedLaravelOrigin,
} from '@/lib/laravel-origin.server';

describe('Laravel server origin', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it.each([
        'http://127.0.0.1:8000',
        'https://127.42.10.9:8443',
        'http://localhost:8000',
        'http://[::1]:8000',
    ])('accepts loopback HTTP origins: %s', (origin) => {
        expect(validatedLaravelOrigin(origin)).toBe(new URL(origin).origin);
    });

    it.each([
        'https://attacker.example',
        'http://169.254.169.254/latest/meta-data/',
        'http://localhost.attacker.example:8000',
        'http://0.0.0.0:8000',
        'http://[::2]:8000',
        'http://[::ffff:127.0.0.1]:8000',
        'ftp://127.0.0.1:8000',
        'http://user:password@127.0.0.1:8000',
        'http://127.0.0.1:8000/api',
        'http://127.0.0.1:8000?token=secret',
        'http://127.0.0.1:8000#fragment',
    ])('rejects unsafe origins: %s', (origin) => {
        expect(() => validatedLaravelOrigin(origin)).toThrow();
    });

    it('uses a loopback fallback only outside production', () => {
        vi.stubEnv('LARAVEL_INTERNAL_URL', '');
        vi.stubEnv('NODE_ENV', 'development');

        expect(resolveLaravelOrigin()).toBe('http://127.0.0.1:8000');

        vi.stubEnv('NODE_ENV', 'production');
        expect(() => resolveLaravelOrigin()).toThrow(
            'LARAVEL_INTERNAL_URL est requis en production.',
        );
    });
});
