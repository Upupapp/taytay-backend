<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

final class UnauthenticatedException extends ApiException
{
    public static function make(): self
    {
        return new self(ErrorCode::Unauthenticated);
    }
}
