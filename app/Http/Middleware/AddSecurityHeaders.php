<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();
        $nonce = Vite::cspNonce();
        $styleSources = ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'];

        if (app()->environment('local')) {
            $styleSources[] = 'http://127.0.0.1:5173';
        }

        /** @var Response $response */
        $response = $next($request);

        $contentSecurityPolicy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            // 'self' (et non 'none') : l'application encadre ses propres documents
            // dans l'apercu des justificatifs. Le cadrage par un site tiers reste bloque.
            "frame-ancestors 'self'",
            "frame-src 'self' blob:",
            "form-action 'self'",
            "object-src 'none'",
            "img-src 'self' data: blob:",
            "media-src 'self' data: blob:",
            "script-src 'self' 'nonce-{$nonce}'",
            'style-src '.implode(' ', $styleSources),
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self' ws: wss:",
        ]);

        $response->headers->set('Content-Security-Policy', $contentSecurityPolicy);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
