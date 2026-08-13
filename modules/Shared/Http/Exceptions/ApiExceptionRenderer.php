<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Http\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps every throwable to the canonical error envelope (docs/api/conventions.md §4).
 *
 * Registered once from bootstrap/app.php so no module can invent its own error shape.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            // Non-API routes (none today) keep framework behaviour.
            if (! $request->is('api/*')) {
                return null;
            }

            return self::render($e);
        });
    }

    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error(
                $e->errorCode,
                $e->getMessage(),
                $e->details,
            ),

            $e instanceof ValidationException => ApiResponse::error(
                ErrorCode::ValidationFailed,
                ErrorCode::ValidationFailed->defaultMessage(),
                $e->errors(),
            ),

            $e instanceof AuthenticationException => ApiResponse::error(ErrorCode::Unauthenticated),

            $e instanceof AuthorizationException => ApiResponse::error(ErrorCode::Forbidden),

            // Deliberately indistinguishable from a genuine 404 so records cannot be
            // enumerated by probing 403-vs-404.
            $e instanceof ModelNotFoundException => ApiResponse::error(ErrorCode::NotFound),

            $e instanceof HttpExceptionInterface => self::fromHttpException($e),

            default => self::fault($e),
        };
    }

    private static function fromHttpException(HttpExceptionInterface&Throwable $e): JsonResponse
    {
        $code = ErrorCode::fromHttpStatus($e->getStatusCode());

        return ApiResponse::error(
            $code,
            // An HTTP exception message may carry internals; only the safe default is
            // returned to the client.
            $code->defaultMessage(),
            [],
            array_filter(
                $e->getHeaders(),
                static fn (string $header): bool => in_array($header, ['Retry-After', 'Allow'], true),
                ARRAY_FILTER_USE_KEY,
            ),
        );
    }

    /**
     * Unhandled fault: the client gets a generic message, the detail goes to the logs.
     */
    private static function fault(Throwable $e): JsonResponse
    {
        $details = [];

        // Local development affordance only. Never enable APP_DEBUG outside local.
        if (config('app.debug') === true) {
            $details['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return ApiResponse::error(ErrorCode::ServerError, null, $details);
    }
}
