<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

/**
 * Per-request correlation state, bound as a scoped container singleton.
 *
 * The request id is echoed in every response header and in every error body so a citizen
 * can quote it to a support desk and staff can find the exact request in the logs.
 */
final class RequestContext
{
    private const MAX_REQUEST_ID_LENGTH = 128;

    private string $requestId;

    private ClientChannel $channel = ClientChannel::Unknown;

    public function __construct(?string $requestId = null)
    {
        $this->requestId = self::sanitizeRequestId($requestId) ?? self::generateRequestId();
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function channel(): ClientChannel
    {
        return $this->channel;
    }

    /**
     * Accepts a client-supplied correlation id only if it is short and alphanumeric —
     * an unvalidated header would otherwise be reflected into logs and response headers.
     */
    public function adoptRequestId(?string $requestId): void
    {
        $sanitized = self::sanitizeRequestId($requestId);

        if ($sanitized !== null) {
            $this->requestId = $sanitized;
        }
    }

    public function setChannel(ClientChannel $channel): void
    {
        $this->channel = $channel;
    }

    private static function sanitizeRequestId(?string $requestId): ?string
    {
        if ($requestId === null) {
            return null;
        }

        $requestId = trim($requestId);

        if ($requestId === '' || strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) === 1 ? $requestId : null;
    }

    private static function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
