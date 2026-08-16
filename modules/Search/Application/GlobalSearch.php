<?php

declare(strict_types=1);

namespace Modules\Search\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;

/**
 * Global search across the records staff work with (ADR 0027).
 *
 * THE ACCEPTANCE CRITERION: **search never returns an object the caller could not open directly.**
 *
 * Held by making every searcher apply the same two gates the detail endpoint applies — the
 * permission that governs reading that kind of record, and the caller's barangay scope — and by
 * returning a result set built from those queries rather than from a separate index that could
 * drift out of step with them.
 *
 * A search index maintained alongside the authorization rules is an index that eventually
 * disagrees with them, and the disagreement is invisible: nobody notices that a search returns
 * one extra row until somebody clicks it and gets a 404 they were not supposed to be able to
 * provoke. So there is no index here, only scoped queries.
 *
 * WHAT IS NEVER SEARCHED: case note bodies, safeguarding detail, referral reasons, visit
 * observations. Those are the four places this system keeps text that a person should not be able
 * to ask questions of — "show me cases whose notes mention 'shelter'" is a disclosure performed by
 * a search box (ADR 0027 §2).
 */
final class GlobalSearch
{
    /** Below this, a term matches too much to be a search and too little to be useful. */
    public const MINIMUM_TERM = 2;

    /** Per entity, so one noisy type cannot fill the whole result set. */
    public const PER_ENTITY_LIMIT = 5;

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * @return array<string, mixed>
     */
    public function search(ActorContext $actor, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MINIMUM_TERM) {
            return ['term' => $term, 'results' => [], 'note' => 'Enter at least two characters.'];
        }

        $results = array_merge(
            $this->residents($actor, $term),
            $this->cases($actor, $term),
            $this->households($actor, $term),
            $this->referrals($actor, $term),
        );

        return ['term' => $term, 'results' => $results];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function residents(ActorContext $actor, string $term): array
    {
        // The same permission the resident detail endpoint requires. Without it, residents are
        // simply absent from the result set — not refused, absent, because a "you may not see
        // these 3 results" message is itself a count.
        if (! $this->authorization->allows($actor, Permission::ResidentView)) {
            return [];
        }

        $query = DB::table('residents')
            ->whereNull('deleted_at')
            ->where(function (Builder $where) use ($term): void {
                $where->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%');
            });

        $this->scope($actor, $query, 'barangay_id');

        return $query->limit(self::PER_ENTITY_LIMIT)->get()->map(static fn (object $row): array => [
            'type' => 'resident',
            'id' => (string) $row->uuid,
            /*
             * A SAFE TITLE. A name, because that is what a clerk searched for and what identifies
             * the row to them — and nothing else: no birth date, no address, no sector, no
             * verification history. A result snippet is a way to find a record, not a way to read
             * one without opening it.
             */
            'title' => trim($row->first_name.' '.$row->last_name),
            'barangay_id' => $row->barangay_id === null ? null : (int) $row->barangay_id,
            'status' => (string) $row->verification_tier,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cases(ActorContext $actor, string $term): array
    {
        if (! $this->authorization->allows($actor, Permission::RequestView)) {
            return [];
        }

        $query = DB::table('welfare_cases')
            ->whereNull('deleted_at')
            ->where('case_number', 'like', '%'.$term.'%');

        /*
         * Restricted case types are excluded unless the caller holds the sensitive permission —
         * the same rule the case list applies (ADR 0016 §5). Knowing that a protection case
         * exists for a searchable reference is most of the disclosure.
         */
        if (! $this->authorization->allows($actor, Permission::RequestViewSensitive)) {
            $query->whereNotIn('type', ['protective']);
        }

        $this->scope($actor, $query, 'barangay_id');

        return $query->limit(self::PER_ENTITY_LIMIT)->get()->map(static fn (object $row): array => [
            'type' => 'case',
            'id' => (string) $row->uuid,
            // The reference, never the narrative. A case number identifies the file; the reason
            // somebody applied is not a search snippet.
            'title' => (string) $row->case_number,
            'barangay_id' => $row->barangay_id === null ? null : (int) $row->barangay_id,
            'status' => (string) $row->status,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function households(ActorContext $actor, string $term): array
    {
        if (! $this->authorization->allows($actor, Permission::ResidentView)) {
            return [];
        }

        $query = DB::table('households')->where('code', 'like', '%'.$term.'%');

        $this->scope($actor, $query, 'barangay_id');

        return $query->limit(self::PER_ENTITY_LIMIT)->get()->map(static fn (object $row): array => [
            'type' => 'household',
            'id' => (string) $row->uuid,
            'title' => (string) $row->code,
            'barangay_id' => $row->barangay_id === null ? null : (int) $row->barangay_id,
            'status' => null,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function referrals(ActorContext $actor, string $term): array
    {
        if (! $this->authorization->allows($actor, Permission::ReferralView)) {
            return [];
        }

        $query = DB::table('referrals')->where('reference_number', 'like', '%'.$term.'%');

        // Referrals carry no barangay of their own; scope runs through the case, exactly as the
        // referral list does (ADR 0021).
        if (! $actor->scope->isUnrestricted()) {
            $query->whereIn('welfare_case_id', function ($sub) use ($actor): void {
                $sub->select('id')->from('welfare_cases')
                    ->whereIn('barangay_id', $actor->scope->barangayIds);
            });
        }

        return $query->limit(self::PER_ENTITY_LIMIT)->get()->map(static fn (object $row): array => [
            'type' => 'referral',
            'id' => (string) $row->uuid,
            // Never `reason` or `destination_contact`.
            'title' => (string) $row->reference_number,
            'barangay_id' => null,
            'status' => (string) $row->status,
        ])->all();
    }

    private function scope(ActorContext $actor, Builder $query, string $column): void
    {
        if ($actor->scope->isUnrestricted()) {
            return;
        }

        $query->whereIn($column, $actor->scope->barangayIds);
    }
}
