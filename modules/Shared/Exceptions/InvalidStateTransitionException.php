<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

/**
 * A lifecycle state machine refused a transition (CLAUDE.md Article 6).
 */
final class InvalidStateTransitionException extends ApiException
{
    public static function between(string $from, string $to): self
    {
        return new self(
            ErrorCode::InvalidStateTransition,
            'The requested state transition is not permitted.',
            ['from' => $from, 'to' => $to],
        );
    }
}
