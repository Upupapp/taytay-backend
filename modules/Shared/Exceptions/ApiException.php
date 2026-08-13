<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for every exception a module deliberately surfaces to a client.
 *
 * Anything thrown that is NOT an ApiException is treated as a fault: it is logged and
 * rendered as a generic SERVER_ERROR, never leaked.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message = '',
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : $errorCode->defaultMessage(),
            $errorCode->httpStatus(),
            $previous,
        );
    }

    public function status(): int
    {
        return $this->errorCode->httpStatus();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function badRequest(string $message = '', array $details = []): self
    {
        return new self(ErrorCode::BadRequest, $message, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function conflict(string $message = '', array $details = []): self
    {
        return new self(ErrorCode::Conflict, $message, $details);
    }
}
