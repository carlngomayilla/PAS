<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureSufficientExecutionTime;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->web(append: [
            EnsureSufficientExecutionTime::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->api(append: [
            AddSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419 || ! $exception->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            $message = 'Votre session a expire. Rechargez la page puis reessayez.';

            if ($request->is('api/*') || $request->expectsJson()) {
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
