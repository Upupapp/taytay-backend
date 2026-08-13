<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

/**
 * The resource does not exist, or the actor may not know that it exists.
 *
 * Deliberately indistinguishable from a genuine 404 so that resident records cannot be
 * enumerated by probing for the difference between 403 and 404.
 */
final class ResourceNotFoundException extends ApiException
{
    public static function make(string $message = ''): self
    {
        return new self(ErrorCode::NotFound, $message);
    }
}
