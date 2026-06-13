<?php

use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureOfficeContext;
use App\Http\Middleware\EnsureStatefulApiRequests;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\TrustForwardedHost;
use App\Support\ArabicMessages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->prepend(TrustForwardedHost::class);
        $middleware->statefulApi();
        $middleware->replaceInGroup(
            'api',
            EnsureFrontendRequestsAreStateful::class,
            EnsureStatefulApiRequests::class,
        );
        $middleware->alias([
            'idempotency' => EnsureIdempotency::class,
            'office.context' => EnsureOfficeContext::class,
            'user.active' => EnsureUserActive::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/login',
            'api/logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                $errors = collect($e->errors())
                    ->map(fn (array $messages) => array_map(
                        fn (string $message) => ArabicMessages::translateApiMessage($message) ?: $message,
                        $messages
                    ))
                    ->all();
                $first = collect($errors)->flatten()->first();

                return response()->json([
                    'message' => $first ?: ArabicMessages::VALIDATION_FAILED,
                    'errors' => $errors,
                ], 422);
            }

            return null;
        });
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                $message = ArabicMessages::translateApiMessage($e->getMessage());
                if ($message === $e->getMessage() || $message === ArabicMessages::VALIDATION_FAILED) {
                    $message = ArabicMessages::FORBIDDEN;
                }

                return response()->json(['message' => $message], 403);
            }

            return null;
        });
        $exceptions->render(function (UnauthorizedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => ArabicMessages::UNAUTHORIZED], 401);
            }

            return null;
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => ArabicMessages::NOT_FOUND], 404);
            }

            return null;
        });
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->expectsJson() && ! app()->hasDebugModeEnabled()) {
                $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
                if ($status >= 500) {
                    return response()->json(['message' => ArabicMessages::SERVER_ERROR], 500);
                }
            }

            return null;
        });
    })->create();
