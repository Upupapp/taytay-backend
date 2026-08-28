<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

/**
 * How much of the municipality an actor may see (ADR 0012).
 *
 * Carried on the ActorContext and resolved server-side from persisted role assignments and
 * explicit grants — never from a token claim, a header or anything a client sends.
 *
 * Lives in Shared because ActorContext does, and Shared may depend on nothing but the
 * framework (CLAUDE.md Article 2.3). The *rules* for building one live in AccessControl.
 */
final readonly class DataScope
{
    public const ALL_BARANGAYS = 'all-barangays';

    public const OWN_BARANGAY = 'own-barangay';

    public const ASSIGNED_CASES = 'assigned-cases';

    public const NONE = 'none';

    /**
     * @param  list<int>  $barangayIds  the barangays this actor may reach, own plus granted
     */
    private function __construct(
        public string $type,
        public array $barangayIds,
    ) {}

    /**
     * Deny by default. A guest, an inactive account, or a staff member whose assignment
     * has expired gets this — not "everything", which is what an unset scope would mean
     * if the default were permissive.
     */
    public static function none(): self
    {
        return new self(self::NONE, []);
    }

    public static function municipality(): self
    {
        return new self(self::ALL_BARANGAYS, []);
    }

    /**
     * @param  list<int>  $barangayIds
     */
    public static function barangays(array $barangayIds): self
    {
        return new self(self::OWN_BARANGAY, array_values(array_unique($barangayIds)));
    }

    /**
     * @param  list<int>  $barangayIds
     */
    public static function assignedCases(array $barangayIds): self
    {
        return new self(self::ASSIGNED_CASES, array_values(array_unique($barangayIds)));
    }

    public function isUnrestricted(): bool
    {
        return $this->type === self::ALL_BARANGAYS;
    }

    public function isNone(): bool
    {
        return $this->type === self::NONE;
    }

    /**
     * Whether this actor may reach a record belonging to a barangay.
     *
     * A null barangay — a record that belongs to no barangay — is reachable only by an
     * unrestricted actor. Treating "unknown" as "yours" is how scoping leaks.
     */
    public function coversBarangay(?int $barangayId): bool
    {
        if ($this->isNone()) {
            return false;
        }

        if ($this->isUnrestricted()) {
            return true;
        }

        return $barangayId !== null && in_array($barangayId, $this->barangayIds, true);
    }

    /**
     * Whether reaching a record additionally requires being its assigned owner.
     */
    public function requiresCaseAssignment(): bool
    {
        return $this->type === self::ASSIGNED_CASES;
    }

    /**
     * Safe for audit logs: the shape of the authority, never the records it reaches.
     *
     * @return array{type: string, barangay_ids: list<int>}
     */
    public function forAudit(): array
    {
        return ['type' => $this->type, 'barangay_ids' => $this->barangayIds];
    }

    /**
     * The same scope for a client, with the barangay codes the directory publishes (L-15).
     *
     * SEPARATE FROM {@see forAudit()} ON PURPOSE. The trail records the identifier the system
     * actually used and its stored shape is append-only, so a new key would start appearing
     * halfway through the record for no reason a reader could see. A client needs the identifier
     * it can resolve. Two needs, two methods, one source.
     *
     * The resolver is passed in rather than reached for: this is a value object in `Shared` and it
     * stays free of the database.
     *
     * A grant whose barangay has since been removed contributes no code, so the lists can differ
     * in length. They are deliberately not zipped into pairs — `barangay_ids` is the shape four
     * clients already read, and this is the expand step of Article 6, not the contract step.
     *
     * @return array{type: string, barangay_ids: list<int>, barangay_codes: list<string>}
     */
    public function forResponse(BarangayCodes $codes): array
    {
        return $this->forAudit() + [
            'barangay_codes' => array_values(array_filter(array_map(
                static fn (int $id): ?string => $codes->codeFor($id),
                $this->barangayIds,
            ))),
        ];
    }
}
