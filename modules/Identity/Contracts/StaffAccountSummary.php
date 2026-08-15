<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/**
 * What another module may learn about a staff account.
 *
 * Deliberately shallow: sign-in identity and status, nothing about password state, MFA
 * secrets, devices, sessions or contact verification. AccessControl needs to render a
 * staff directory, not to inspect an authentication record (CLAUDE.md Article 5.2).
 */
final readonly class StaffAccountSummary
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $email,
        public string $status,
        public ?string $lastSignedInAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'email' => $this->email,
            'status' => $this->status,
            'last_signed_in_at' => $this->lastSignedInAt,
        ];
    }
}
