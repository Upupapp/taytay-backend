<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

/**
 * The canonical, stable machine-readable error vocabulary (docs/api/conventions.md §4).
 *
 * Clients branch on these values, so a case may be added but never renamed or removed
 * within /api/v1.
 */
enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case ValidationFailed = 'VALIDATION_FAILED';
    case RateLimited = 'RATE_LIMITED';

    /*
     * Upload failures, distinguished from generic validation because the client's recovery
     * differs: too large means "take a photo instead", wrong type means "we cannot accept this
     * file at all". Both clients already have fixed resident-facing copy for each.
     */
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';

    case ServerError = 'SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    public function httpStatus(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict, self::InvalidStateTransition => 409,
            self::ValidationFailed => 422,
            self::RateLimited => 429,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::ServerError => 500,
            self::ServiceUnavailable => 503,
        };
    }

    /**
     * Safe, operator-facing default. Never includes internals or personal data.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be understood.',
            self::Unauthenticated => 'Authentication is required.',
            self::Forbidden => 'You are not allowed to perform this action.',
            self::NotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'The HTTP method is not supported for this resource.',
            self::Conflict => 'The request conflicts with the current state of the resource.',
            self::InvalidStateTransition => 'The requested state transition is not permitted.',
            self::ValidationFailed => 'The given data was invalid.',
            self::RateLimited => 'Too many requests. Please try again later.',
            self::PayloadTooLarge => 'That file is larger than this endpoint accepts.',
            self::UnsupportedMediaType => 'That file type cannot be accepted.',
            self::ServerError => 'An unexpected error occurred.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
        };
    }

    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            422 => self::ValidationFailed,
            413 => self::PayloadTooLarge,
            415 => self::UnsupportedMediaType,
            429 => self::RateLimited,
            503 => self::ServiceUnavailable,
            default => $status >= 500 ? self::ServerError : self::BadRequest,
        };
    }
}
