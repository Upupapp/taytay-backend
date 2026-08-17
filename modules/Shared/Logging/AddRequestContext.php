<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Monolog\LogRecord;

/**
 * Puts the correlation context on every log record (ADR 0037 §1).
 *
 * WHAT A LOG LINE HAS TO ANSWER is "which request was this, and who was acting" — because the
 * question is never asked about a log line in isolation. It is asked because a citizen quoted a
 * request id at a support desk, or because an audit entry named an act and somebody wants the
 * lines around it.
 *
 * So every record carries the same `request_id` the response header carried and the audit entry
 * recorded. Those three being the same string is what makes a support call tractable: the resident
 * has it on their screen, the trail has it on the row, and the log has it on the line.
 *
 * WHAT IT DELIBERATELY DOES NOT CARRY: a name, an email, a resident id, a case narrative. The
 * actor is a subject UUID — enough to correlate, not enough to identify without a second,
 * authorized lookup. That is the same trade `OutboundNotification::routingPayload()` makes for push
 * (Article 8.4), and for the same reason: this string travels somewhere less guarded than the
 * record it describes.
 */
final class AddRequestContext
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = request();

        $extra = [
            'environment' => (string) config('app.env'),
            'service' => (string) config('api.service_name'),
        ];

        if ($request !== null) {
            $extra += array_filter([
                // The same string as the X-Request-Id header and the audit entry.
                'request_id' => $request->attributes->get('request_id'),
                'method' => $request->getMethod(),
                // The ROUTE PATTERN, not the URL. `admin/residents/{resident}` groups a thousand
                // requests together; the resolved URL puts a resident identifier in every line.
                'route' => $request->route()?->uri(),
                'channel' => $request->headers->get('X-Client-Channel'),
                /*
                 * A subject UUID and nothing else. Enough to correlate, not enough to identify
                 * without an authorized lookup — the same trade a push payload makes.
                 */
                'actor' => $request->user()?->uuid,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $record->with(extra: $record->extra + $extra);
    }
}
