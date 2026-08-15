<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders()
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',

        then: function () {
            Route::middleware('web')->group(__DIR__ . '/../routes/app.php');
            Route::middleware('api')->group(__DIR__ . '/../routes/api.php');
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Remove EnsureFrontendRequestsAreStateful for token-based API
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Sanitasi error di production: jangan bocorkan detail internal
        // (SQL mentah, trace, path file) ke client. Exception yang punya
        // respons terstruktur (ValidationException 422, AuthenticationException
        // 401, dsb.) tetap memakai render default Laravel.
        $exceptions->renderable(function (Throwable $e, $request) {
            $isApiRequest = $request->expectsJson() || $request->is('api/*');

            if (!app()->isProduction() || !$isApiRequest) {
                return null; // biarkan handler default
            }

            // Exception yang sudah punya rendering sendiri dibiarkan default.
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            // Log exception lengkap untuk debugging internal.
            Log::error('Exception tidak tertangani: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'url' => $request->fullUrl(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal server.',
            ], 500);
        });
    })
    ->create();
