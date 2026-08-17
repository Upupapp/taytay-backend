<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Audit\Application\AuditActionCatalog;
use Modules\Audit\Application\AuditQuery;
use Modules\Audit\Application\AuditTrail;
use Modules\Audit\Domain\AuditRisk;
use Modules\Audit\Infrastructure\Eloquent\AuditEntry;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Reading the audit trail (ADR 0034 §7).
 *
 * **READING IS ITSELF AUDITED**, and that is not decoration. The trail is more concentrated than
 * any single record it describes — a search for `safeguarding.opened` tells you which residents
 * have protection cases without opening one — so the office needs to be able to answer "who has
 * been reading the audit log", and the only way for that question to have an answer is for the
 * reads to be in the log too.
 *
 * A search is audited once per search, not once per row. A row-level trail of a hundred-row page
 * would bury the act that matters in the noise of the act that revealed it.
 */
final class AuditController
{
    public function __construct(
        private readonly AuditQuery $entries,
        private readonly AuditTrail $trail,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::AuditView);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->entries->query();

        foreach (['action', 'entity_type', 'entity_id', 'actor_subject_id', 'request_id', 'risk'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        foreach (['from' => '>=', 'to' => '<='] as $bound => $operator) {
            $value = $request->query($bound);

            if (is_string($value) && $value !== '') {
                $query->where('occurred_at', $operator, $value);
            }
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        /*
         * The search is recorded with WHAT WAS ASKED FOR, by parameter name, and never the answer.
         * "Somebody searched the trail for safeguarding events on Tuesday" is the finding; the
         * rows they saw are already in the trail and do not need a second copy.
         */
        $this->trail->record(
            $actor->subjectId,
            'audit.searched',
            sprintf('Audit trail searched (%d matches)', $total),
            'Audit.Entry',
            null,
            array_keys($request->query()),
        );

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (AuditEntry $entry): array => $this->projection($entry),
        );
    }

    public function show(Request $request, ActorContext $actor, string $entry): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::AuditView);

        /** @var AuditEntry|null $model */
        $model = AuditEntry::query()->where('uuid', $entry)->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That audit entry was not found.');
        }

        return ApiResponse::item($this->projection($model));
    }

    /**
     * Everything recorded about one record, oldest first.
     */
    public function forEntity(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::AuditView);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'max:64'],
            'entity_id' => ['required', 'string', 'max:64'],
        ]);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->entries->forEntity($validated['entity_type'], $validated['entity_id']);

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        $this->trail->record(
            $actor->subjectId,
            'audit.searched',
            'Audit trail read for one record',
            $validated['entity_type'],
            $validated['entity_id'],
        );

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (AuditEntry $auditEntry): array => $this->projection($auditEntry),
        );
    }

    /**
     * The vocabulary, so a console can build its own filters without hardcoding a list.
     */
    public function vocabulary(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::AuditView);

        return ApiResponse::item([
            'risks' => AuditRisk::values(),
            'high_risk_actions' => AuditActionCatalog::declared(),
            'network_capture_enabled' => config('audit.capture_network', false) === true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(AuditEntry $entry): array
    {
        return [
            'id' => $entry->uuid,
            'occurred_at' => $entry->occurred_at?->toIso8601ZuluString(),
            'actor_subject_id' => $entry->actor_subject_id,
            'actor_account_type' => $entry->actor_account_type,
            'action' => $entry->action,
            'risk' => $entry->risk->value,
            'entity_type' => $entry->entity_type,
            'entity_id' => $entry->entity_id,
            'summary' => $entry->summary,
            /*
             * FIELD NAMES, never values. Emitted as a list so a console cannot render it as a
             * before/after diff — there is nothing here to diff, and a UI that implied otherwise
             * would send somebody looking for data that deliberately does not exist.
             */
            'changed_fields' => $entry->changedFieldNames(),
            'reason' => $entry->reason,
            // The correlation id, which is what ties one act to the request a citizen can quote.
            'request_id' => $entry->request_id,
            'client_channel' => $entry->client_channel,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
        ];
    }
}
