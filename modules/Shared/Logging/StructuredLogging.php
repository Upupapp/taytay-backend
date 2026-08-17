<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Illuminate\Log\Logger;

/**
 * The Monolog tap that wires both processors onto every channel (ADR 0037 §1–§2).
 *
 * **THE PUSH ORDER IS THE REVERSE OF THE RUN ORDER, AND GETTING IT WRONG IS SILENT.**
 *
 * Monolog invokes processors last-pushed-first. So to have redaction run *last* — over the context
 * the other processor just added — redaction must be pushed *first*:
 *
 *     push(RedactSensitiveData)   stack: [Redact]
 *     push(AddRequestContext)     stack: [AddRequestContext, Redact]
 *     invoked:                    AddRequestContext, then Redact   ✔
 *
 * The intuitive order does the opposite: redaction runs first, `AddRequestContext` then appends
 * its fields to an already-scrubbed record, and anything it adds is never scrubbed at all. Nothing
 * about the resulting log line looks wrong — the redacted fields are still redacted, because they
 * were in the original context — so the failure would only surface the day the context processor
 * started carrying something worth hiding.
 *
 * `LoggingRedactionTest::redaction_runs_after_the_context_processor` asserts the composition
 * directly rather than trusting the comment.
 */
final class StructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if (! method_exists($handler, 'pushProcessor')) {
                continue;
            }

            // Pushed first so it RUNS LAST. See the class docblock.
            $handler->pushProcessor(new RedactSensitiveData);
            $handler->pushProcessor(new AddRequestContext);
        }
    }
}
