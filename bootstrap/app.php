<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AddSecurityHeaders::class,
        ]);

        $middleware->api(append: [
            AddSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            $message = 'Votre session a expire. Rechargez la page puis reessayez.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'csrf_expired' => true,
                ], 419);
            }

            $redirect = $request->headers->has('referer')
                ? redirect()->back()
                : redirect()->route('login.form');

            return $redirect
                ->withInput($request->except([
                    '_token',
                    'current_password',
                    'password',
                    'password_confirmation',
                    'new_password',
                    'new_password_confirmation',
                ]))
                ->withErrors(['csrf' => $message]);
        });
    })->create();
