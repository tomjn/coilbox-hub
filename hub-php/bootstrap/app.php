<?php

use App\Support\Api;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The desktop app's API. No session, no CSRF: callers send a bearer
            // token or nothing. One file per area, so parallel work does not
            // collide.
            Route::prefix('api/v1')->middleware('api')->group(function () {
                foreach (glob(base_path('routes/api/*.php')) as $file) {
                    require $file;
                }
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the web server on the same machine.
        $middleware->trustProxies(at: '127.0.0.1');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API answers errors as `{"error": "..."}` with CORS headers, as the
        // Next app did, never Laravel's default shape.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status === 404 ? 'Not found.' : ($status >= 500 ? 'Something went wrong on the hub.' : $e->getMessage());

            return Api::error($message, $status);
        });
    })->create();
