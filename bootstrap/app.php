<?php

use App\Enums\ErrorCode;
use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\EnsureSingleSession;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use App\Http\Middleware\MarkNotificationsAsRead;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__ . '/../routes/console.php',
        using: function () {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api-v1.php'));

            Route::middleware('api')
                ->as('admin.')
                ->prefix('api/v1/admin')
                ->group(base_path('routes/admin-v1.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('web')
                ->prefix('developer')
                ->group(base_path('routes/developer.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'developer' => Spatie\LittleGateKeeper\AuthMiddleware::class,
            'notification-read' => MarkNotificationsAsRead::class,
            // FinTrack. `single.session` and `can.write` read from cache only —
            // they run on every authenticated request, so a query in either
            // would be thousands of needless round trips per second at load.
            'single.session' => EnsureSingleSession::class,
            'can.write' => EnsureCanWrite::class,
            'idempotent' => Idempotent::class,
        ]);
        $middleware->group('api', [
            'throttle:api',
            SetLocale::class,
            Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
        |----------------------------------------------------------------------
        | One place turns every failure into the standard error envelope.
        |----------------------------------------------------------------------
        |
        | Because these renderers exist, there is not a single try/catch in the
        | HTTP layer: services throw, controllers stay thin, and every client
        | sees the same shape with a machine-readable `error_code`.
        |
        | Order matters — the most specific handler must be registered first.
        */

        // Business-rule failures. Each carries its own code, status and meta.
        $exceptions->render(function (DomainException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(array_filter([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode()->value,
                'meta' => $e->meta() ?: null,
            ], static fn ($value) => $value !== null), $e->status());
        });

        $exceptions->render(function (ValidationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => (string) __('errors.validation_failed'),
                'error_code' => ErrorCode::ValidationFailed->value,
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => (string) __('errors.unauthenticated'),
                'error_code' => ErrorCode::Unauthenticated->value,
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => (string) __('errors.forbidden'),
                'error_code' => ErrorCode::Forbidden->value,
            ], 403);
        });

        // Rate limiting. Retry-After is passed through so the client can show
        // "try again in N seconds" rather than guessing.
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return response()->json(array_filter([
                'success' => false,
                'message' => (string) __('errors.rate_limited'),
                'error_code' => ErrorCode::RateLimited->value,
                'meta' => $retryAfter !== null ? ['retry_after' => (int) $retryAfter] : null,
            ], static fn ($value) => $value !== null), 429, $e->getHeaders());
        });

        // Missing model or unknown route. A 404 body must never echo the model
        // name or path back — that is free reconnaissance.
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => (string) __('errors.not_found'),
                'error_code' => ErrorCode::NotFound->value,
            ], 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => (string) __('errors.not_found'),
                'error_code' => ErrorCode::NotFound->value,
            ], 405);
        });
    })->create();
