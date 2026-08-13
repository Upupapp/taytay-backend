<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

/**
 * The client channel a request claims to have arrived from.
 *
 * TELEMETRY AND PRESENTATION DEFAULTS ONLY.
 *
 * Per CLAUDE.md Article 3.3 and ADR 0002, the channel is supplied by the client and is
 * therefore untrusted: it may be recorded for audit and may pick a default page size, but
 * it must never grant, widen or imply permission. Never branch an authorization decision
 * on this value.
 */
enum ClientChannel: string
{
    case CitizenWeb = 'citizen-web';
    case CitizenMobile = 'citizen-mobile';
    case AdminConsole = 'admin-console';
    case VerifierDevice = 'verifier-device';
    case Unknown = 'unknown';

    /**
     * Unrecognised or absent channels degrade to Unknown and proceed with identical
     * authority — an unparseable header is never a reason to fail a citizen's request.
     */
    public static function fromHeader(?string $value): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Unknown;
    }

    public function isCitizen(): bool
    {
        return $this === self::CitizenWeb || $this === self::CitizenMobile;
    }

    /**
     * Presentation default only. A smaller page suits a phone on a poor connection.
     */
    public function defaultPerPage(): int
    {
        return $this === self::CitizenMobile ? 15 : 25;
    }
}
