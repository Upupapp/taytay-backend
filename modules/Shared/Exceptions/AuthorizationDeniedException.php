<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

/**
 * The server-side authorization decision said no.
 *
 * Thrown by AccessControl, never by a controller guessing. Renders as 403 FORBIDDEN.
 * When merely confirming that the resource exists would leak personal data, throw
 * {@see ResourceNotFoundException} instead (docs/api/conventions.md §4).
 */
final class AuthorizationDeniedException extends ApiException
{
    public static function forPermission(string $permission): self
    {
        // The permission name is safe to return: it is a fixed vocabulary, not user data,
        // and it lets a legitimate client show a useful message.
        return new self(
            ErrorCode::Forbidden,
            'You are not allowed to perform this action.',
            ['required_permission' => $permission],
        );
    }
}
