<?php

declare(strict_types=1);

namespace Modules\Shared\Http;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use JsonSerializable;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\RequestContext;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Http\Middleware\AssignRequestId;

/**
 * The single builder for every /api/v1 payload (docs/api/conventions.md).
 *
 * Endpoints must use these helpers instead of returning arrays or `response()->json()`
 * directly, so that envelope shape cannot drift between modules.
 */
final class ApiResponse
{
    /**
     * Single resource: { "data": ..., "meta": { "request_id": ... } }.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function item(
        array|Arrayable|JsonSerializable|null $data,
        int $status = 200,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse([
            'data' => self::normalize($data),
            'meta' => self::withRequestId($meta),
        ], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(
        array|Arrayable|JsonSerializable $data,
        ?string $location = null,
        array $meta = [],
    ): JsonResponse {
        return self::item($data, 201, $meta, $location !== null ? ['Location' => $location] : []);
    }

    public static function noContent(): Response
    {
        return new Response('', 204);
    }

    /**
     * Paginated collection. Collections are ALWAYS paginated — never return an unbounded
     * list (CLAUDE.md Article 4).
     *
     * @param  Page<mixed>  $page
     * @param  null|callable(mixed): mixed  $transform
     * @param  array<string, mixed>  $meta
     */
    public static function page(Page $page, ?callable $transform = null, array $meta = []): JsonResponse
    {
        $items = $transform !== null ? array_map($transform, $page->items) : $page->items;

        return new JsonResponse([
            'data' => array_values(array_map(self::normalize(...), $items)),
            'meta' => self::withRequestId($meta + ['pagination' => $page->meta()]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        ErrorCode $code,
        ?string $message = null,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $error = [
            'code' => $code->value,
            'message' => $message ?? $code->defaultMessage(),
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        $error['request_id'] = self::requestId();

        // The header is set here as well as in AssignRequestId because an exception
        // unwinds the middleware pipeline: the middleware's after-stage never runs for an
        // error response, and the id a citizen quotes from the body must always match the
        // one in the header.
        $headers[AssignRequestId::HEADER] = self::requestId();

        return new JsonResponse(['error' => $error], $code->httpStatus(), $headers);
    }

    private static function normalize(mixed $data): mixed
    {
        return match (true) {
            $data instanceof Arrayable => $data->toArray(),
            $data instanceof JsonSerializable => $data->jsonSerialize(),
            default => $data,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withRequestId(array $meta): array
    {
        return ['request_id' => self::requestId()] + $meta;
    }

    private static function requestId(): string
    {
        return app(RequestContext::class)->requestId();
    }
}
