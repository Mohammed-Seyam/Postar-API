<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\ForceHttps::class);
        $middleware->append(\App\Http\Middleware\SetSecurityHeaders::class);

        $middleware->alias([
            'plan' => \App\Http\Middleware\CheckPlanLimits::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error('Validation Failed: ', $e->errors());
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $exception, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'Too many requests from this IP address.',
                    'code' => 429,
                    'message' => 'Please try again after '.
                                ($exception->getHeaders()['Retry-After'] ?? '60').
                                ' seconds',
                    'retry_after' => intval($exception->getHeaders()['Retry-After'] ?? 60),
                ], 429);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found',
                    'code' => 404,
                ], 404);
            }
        });

        $exceptions->render(function (\PDOException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                \Illuminate\Support\Facades\Log::error('Database Error: '.$e->getMessage());
                if (! config('app.debug')) {
                    return response()->json([
                        'message' => 'Database error. Please contact support.',
                        'code' => 500,
                    ], 500);
                }
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                if ($e->getStatusCode() === 500) {
                    \Illuminate\Support\Facades\Log::error('Server Error: '.$e->getMessage());
                    if (! config('app.debug')) {
                        return response()->json([
                            'message' => 'Server error. Please try again later.',
                            'code' => 500,
                        ], 500);
                    }
                }
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 401,
                ], 401);
            }
        });
    })->create();
