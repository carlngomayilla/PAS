import { Buffer } from 'node:buffer';
import { NextRequest, NextResponse } from 'next/server';

export function proxy(request: NextRequest): NextResponse {
    const nonce = Buffer.from(crypto.randomUUID()).toString('base64');
    const isDevelopment = process.env.NODE_ENV === 'development';
    const contentSecurityPolicy = [
        "default-src 'self'",
        `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${isDevelopment ? " 'unsafe-eval'" : ''}`,
        `style-src 'self' 'nonce-${nonce}'`,
        "img-src 'self' blob: data:",
        "font-src 'self'",
        `connect-src 'self'${isDevelopment ? ' ws: wss:' : ''}`,
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        ...(!isDevelopment ? ['upgrade-insecure-requests'] : []),
    ].join('; ');
    const requestHeaders = new Headers(request.headers);

    requestHeaders.set('x-nonce', nonce);
    requestHeaders.set('Content-Security-Policy', contentSecurityPolicy);

    const response = NextResponse.next({
        request: {
            headers: requestHeaders,
        },
    });

    response.headers.set('Content-Security-Policy', contentSecurityPolicy);
    response.headers.set('Referrer-Policy', 'strict-origin-when-cross-origin');
    response.headers.set('X-Content-Type-Options', 'nosniff');
    response.headers.set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    return response;
}

export const config = {
    matcher: [
        {
            source: '/((?!api|_next/static|_next/image|favicon.ico).*)',
            missing: [
                { type: 'header', key: 'next-router-prefetch' },
                { type: 'header', key: 'purpose', value: 'prefetch' },
            ],
        },
    ],
};
