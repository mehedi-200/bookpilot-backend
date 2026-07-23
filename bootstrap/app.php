<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveWidget;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'widget' => ResolveWidget::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $apiError = fn (string $message, int $code, $errors = null) => response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);

        $exceptions->render(function (ValidationException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError('The given data was invalid.', 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError('Unauthenticated.', 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError('This action is unauthorized.', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError('Resource not found.', 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($apiError) {
            if ($request->is('api/*')) {
                return $apiError('Too many requests. Please slow down.', 429);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($apiError) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return $apiError('Something went wrong on our side.', 500);
            }
        });
    })->create();
