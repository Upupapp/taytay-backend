<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\IdempotencyService;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\ReleaseService;
use Modules\Welfare\Domain\ReleaseStatus;
use Modules\Welfare\Infrastructure\Eloquent\Release;
use Modules\Welfare\Infrastructure\Eloquent\ReleaseBatch;
use Modules\Welfare\Infrastructure\Eloquent\ReleaseTransition;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Release and distribution tracking, for staff (ADR 0023).
 *
 * TWO PERMISSIONS, AND THE SPLIT IS THE POINT:
 *
 *  * `request.schedule` — prepare a release, defer it, cancel it, run a batch. Scheduling
 *    decisions a caseworker makes.
 *  * `request.release` — **confirm that assistance was handed over.** Held by
 *    `disbursing_officer` and by nobody who can approve a case.
 *
 * And a third control that is not a permission at all: the person who *approved* the case may not
 * release its money, checked against the approver snapshot at confirmation time. Two roles is the
 * design; one person holding both is the failure, and it arrives the moment somebody is granted a
 * second role to cover a colleague's leave (ADR 0023 §3).
 */
final class ReleaseController
{
    public function __construct(
        private readonly ReleaseService $releases,
        private readonly ResidentDirectory $residents,
        private readonly IdempotencyService $idempotency,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->releases->query();

        foreach (['status', 'kind', 'release_mode', 'resident_id', 'program_id'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        foreach ([['from', '>='], ['to', '<=']] as [$param, $operator]) {
            $value = $request->query($param);

            if (is_string($value) && $value !== '') {
                $query->whereDate('scheduled_for', $operator, $value);
            }
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Release $release): array => $this->projection($release, $actor),
        );
    }

    public function show(Request $request, ActorContext $actor, string $release): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->releaseOrFail($actor, $release);

        return ApiResponse::item($this->projection($model, $actor) + [
            'transitions' => $model->transitions()->get()->map(fn (ReleaseTransition $t): array => [
                'from' => $t->from_status,
                'to' => $t->to_status,
                'reason' => $t->reason,
                'occurred_at' => $t->occurred_at?->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    /**
     * Prepares a release against an approved case.
     */
    public function store(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestSchedule);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:cash,in-kind'],
            // INTEGER CENTAVOS. Never a decimal string, never a float — a peso figure that has
            // been through a float is a peso figure nobody can reconcile (ADR 0023 §1).
            'amount_centavos' => ['required_if:kind,cash', 'nullable', 'integer', 'min:1'],
            'in_kind_description' => ['required_if:kind,in-kind', 'nullable', 'string', 'max:255'],
            'release_mode' => ['required', 'string', 'max:32'],
            'program_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'funding_source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'approval_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'release_location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /*
         * IDEMPOTENT (TAB 08 step 3). Creating a release is a money write: a double-click or a
         * retry on a bad connection would schedule a second payout for the same family, and
         * nothing downstream would recognise it as a duplicate — the two rows differ only by
         * `sequence`, which the table's unique key positively expects.
         */
        [$status, $body] = $this->idempotency->execute(
            $this->idempotencyKeyOrFail($request),
            $actor->subjectId,
            'POST /api/v1/admin/assistance-requests/{case}/releases',
            ['case' => $case] + $validated,
            fn (): array => [201, $this->projection($this->releases->prepare($model, $validated, $actor), $actor)],
        );

        return ApiResponse::item($body, $status);
    }

    /**
     * Confirms that assistance was handed over.
     *
     * IDEMPOTENT. `Idempotency-Key` is not optional here in practice: a payout table has a weak
     * connection and a queue behind it, and an unprotected retry records a second release for a
     * family that received once. The key replays the first answer; the row lock inside the
     * service handles the different failure of two staff clicking at the same moment.
     */
    public function confirm(Request $request, ActorContext $actor, string $release): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestRelease);

        $model = $this->releaseOrFail($actor, $release);

        $validated = $request->validate([
            'acknowledged_by_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            // Frequently not the beneficiary: an elderly person sends a daughter. Recording only
            // "released" loses the one fact a dispute turns on.
            'acknowledged_relationship' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The METHOD only. No signature image, no thumbprint — the mark stays on the paper.
            'acknowledgement_method' => ['sometimes', 'nullable', 'string', 'in:signature,thumbmark,digital-confirmation,witnessed'],
            /*
             * WHAT THE OFFICER WROTE ON THE VOUCHER. Not an account code, nothing joins on it
             * (`DL-89`) — it is the identifier a reconciliation is performed against by a person,
             * and a payout that cannot be tied back to its cheque number is one nobody can check.
             */
            'instrument_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        [$status, $body] = $this->idempotency->execute(
            $this->idempotencyKeyOrFail($request),
            $actor->subjectId,
            'POST /api/v1/admin/releases/{release}/confirmation',
            ['release' => $release] + $validated,
            function () use ($model, $validated, $actor): array {
                return [200, $this->projection($this->releases->confirmRelease($model, $validated, $actor), $actor)];
            },
        );

        return ApiResponse::item($body, $status);
    }

    /**
     * Completed, failed, deferred or cancelled.
     */
    public function transition(Request $request, ActorContext $actor, string $release): JsonResponse
    {
        $model = $this->releaseOrFail($actor, $release);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:completed,failed,deferred,cancelled,ready'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            /*
             * ACCEPTED ON `completed`, WHICH IS THE ACKNOWLEDGEMENT.
             *
             * A payout is frequently handed over before the beneficiary signs for it — a bank
             * transfer sent, a relief pack issued and receipted later. The console models that as
             * two steps (`released` then `claimed`) and collects who actually collected it at the
             * second one, where this endpoint had no field for it.
             *
             * Still the METHOD only. No signature image, no thumbprint: the mark stays on the
             * paper manifest, because a biometric held for this purpose is one held for no reason
             * (RA 10173, Article 5.2).
             */
            'acknowledged_by_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'acknowledged_relationship' => ['sometimes', 'nullable', 'string', 'max:64'],
            'acknowledgement_method' => ['sometimes', 'nullable', 'string', 'in:signature,thumbmark,digital-confirmation,witnessed'],
        ]);

        $target = ReleaseStatus::from($validated['status']);

        // The permission comes from the TARGET state, not from the route — `completed` closes out
        // a handover and belongs to whoever may release, while deferring is scheduling.
        $this->authorization->authorize($actor, $target->requiredPermission());

        /*
         * IDEMPOTENT. `ready → released` is the moment money moves, so a replayed request here is
         * the worst case in the system. The stored response is replayed rather than the transition
         * being attempted twice.
         *
         * The row lock inside the service handles the *different* failure — two officers pressing
         * at the same instant with different keys — and the two protections are not
         * interchangeable: a key defends against one caller retrying, a lock against two callers
         * racing.
         */
        [$httpStatus, $body] = $this->idempotency->execute(
            $this->idempotencyKeyOrFail($request),
            $actor->subjectId,
            'POST /api/v1/admin/releases/{release}/status',
            ['release' => $release] + $validated,
            fn (): array => [200, $this->projection($this->releases->transition(
                $model,
                $target,
                $validated['reason'] ?? null,
                $actor,
                array_intersect_key($validated, array_flip([
                    'acknowledged_by_name',
                    'acknowledged_relationship',
                    'acknowledgement_method',
                ])),
            ), $actor)],
        );

        return ApiResponse::item($body, $httpStatus);
    }

    // ── batches and manifests ─────────────────────────────────────────────────────────

    public function storeBatch(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestSchedule);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'scheduled_for' => ['required', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // A duplicate batch is not a duplicate payout, but it is a second list an officer can
        // work from, and two lists for one distribution is how somebody gets paid off both.
        [$status, $body] = $this->idempotency->execute(
            $this->idempotencyKeyOrFail($request),
            $actor->subjectId,
            'POST /api/v1/admin/release-batches',
            $validated,
            function () use ($validated, $actor): array {
                /** @var ReleaseBatch $batch */
                $batch = ReleaseBatch::query()->create($validated + [
                    'status' => 'open',
                    'opened_by' => $actor->subjectId,
                ]);

                return [201, $this->batchProjection($batch)];
            },
        );

        return ApiResponse::item($body, $status);
    }

    public function addToBatch(Request $request, ActorContext $actor, string $batch): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestSchedule);

        $model = $this->batchOrFail($batch);

        $validated = $request->validate([
            'release_id' => ['required', 'string', 'max:64'],
        ]);

        $release = $this->releaseOrFail($actor, $validated['release_id']);

        if ($release->status !== ReleaseStatus::Ready) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Only a release that is ready can be added to a distribution run.',
            );
        }

        [$status, $body] = $this->idempotency->execute(
            $this->idempotencyKeyOrFail($request),
            $actor->subjectId,
            'POST /api/v1/admin/release-batches/{batch}/releases',
            ['batch' => $batch] + $validated,
            function () use ($release, $model, $actor): array {
                $release->forceFill(['release_batch_id' => $model->id])->save();

                return [200, $this->projection($release->refresh(), $actor)];
            },
        );

        return ApiResponse::item($body, $status);
    }

    /**
     * The distribution runs (TAB 08), closing `ReleaseRepository.listBatches`.
     *
     * **Counts, never a status of its own** (`DL-90`). A batch is a plan — a date, a venue, an
     * officer and a list — and what it amounts to is derived by counting its members. "38 of 41
     * released, 2 deferred" names the problem; "partially complete" hides the two people still
     * waiting.
     */
    public function indexBatches(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $pagination = PaginationParams::fromRequest($request);

        $query = ReleaseBatch::query()->orderByDesc('scheduled_for')->orderByDesc('id');

        $scheduledFor = $request->query('scheduled_for');

        if (is_string($scheduledFor) && $scheduledFor !== '') {
            $query->whereDate('scheduled_for', $scheduledFor);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (ReleaseBatch $batch): array => $this->batchProjection($batch),
        );
    }

    /** One distribution run, with the counts that say where it stands. */
    public function showBatch(Request $request, ActorContext $actor, string $batch): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        return ApiResponse::item($this->batchProjection($this->batchOrFail($batch)));
    }

    /**
     * Totals a disbursing officer can tie to the office's own records (TAB 08 step 9).
     *
     * *"A figure nobody can reconcile is a figure nobody trusts."*
     *
     * ── WHAT IS AND IS NOT SUPPRESSED HERE ───────────────────────────────────────────
     *
     * Reporting suppresses small cells because an aggregate of one identifies a household. This is
     * **not** a report: it is the disbursing officer's own ledger view, reached with
     * `request.release`, and its purpose is to tie to a cash count at the end of a distribution
     * day. A withheld row would make it impossible to balance — the officer would be told the
     * total does not add up and not told which row was removed.
     *
     * So no suppression, and the narrower permission is what pays for that. It also carries no
     * names: totals by status, by programme and by period, and nothing that identifies a person.
     */
    public function reconciliation(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestRelease);

        $query = $this->releases->query();

        foreach ([['from', '>='], ['to', '<=']] as [$param, $operator]) {
            $value = $request->query($param);

            if (is_string($value) && $value !== '') {
                $query->whereDate('scheduled_for', $operator, $value);
            }
        }

        $rows = (clone $query)
            ->selectRaw('status, program_code, kind, count(*) as line_count')
            ->selectRaw('sum(case when kind = ? then coalesce(amount_centavos, 0) else 0 end) as centavos', ['cash'])
            ->groupBy('status', 'program_code', 'kind')
            ->get();

        $byStatus = [];
        $byProgram = [];

        /*
         * Accumulated with a named helper rather than references held inside an array. The first
         * version used `foreach ([[&$byStatus, ...], [&$byProgram, ...]] as [$bucket, $key])`, and
         * PHP's list destructuring drops the reference — both buckets were copies, so the totals
         * were right and every breakdown came back empty. Caught by the assertion that the parts
         * must add up to the whole, which is the one job this endpoint has.
         */
        $accumulate = static function (array &$bucket, string $key, object $row): void {
            $bucket[$key] ??= ['key' => $key, 'line_count' => 0, 'centavos' => 0, 'in_kind_count' => 0];
            $bucket[$key]['line_count'] += (int) $row->line_count;
            $bucket[$key]['centavos'] += (int) $row->centavos;

            if ($row->kind === 'in-kind') {
                $bucket[$key]['in_kind_count'] += (int) $row->line_count;
            }
        };

        foreach ($rows as $row) {
            $statusKey = $row->status instanceof ReleaseStatus ? $row->status->value : (string) $row->status;

            $accumulate($byStatus, $statusKey, $row);
            $accumulate($byProgram, (string) ($row->program_code ?? 'unassigned'), $row);
        }

        return ApiResponse::item([
            'by_status' => array_values($byStatus),
            'by_program' => array_values($byProgram),
            'totals' => [
                'line_count' => (int) $rows->sum('line_count'),
                'centavos' => (int) $rows->sum('centavos'),
                /*
                 * Goods are counted beside the money, never summed into it (`DL-93`). Nobody priced
                 * that sack of rice, and a peso total that silently included it as zero is a total
                 * that cannot be tied to anything.
                 */
                'in_kind_count' => (int) $rows->where('kind', 'in-kind')->sum('line_count'),
                'currency' => 'PHP',
            ],
            'filters' => array_filter([
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ]),
        ]);
    }

    /**
     * The manifest for a distribution run.
     */
    public function manifest(Request $request, ActorContext $actor, string $batch): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->batchOrFail($batch);

        $rows = $this->releases->manifestQuery((string) $model->uuid)->get();

        return ApiResponse::item([
            'batch' => $this->batchProjection($model),
            /*
             * Ordered by reference, not by name. Two copies printed an hour apart then match line
             * for line, which is what makes a paper manifest checkable against a screen at a
             * table with a queue in front of it.
             */
            'lines' => $rows->map(fn (Release $release): array => $this->projection($release, $actor))->all(),
            'total_count' => $rows->count(),
            /*
             * A total in CENTAVOS, summed as integers. In-kind releases contribute nothing —
             * a relief pack has a notional value, and adding it here would produce a peso figure
             * that says cash was handed over when rice was.
             */
            'total_cash_centavos' => $rows->sum(
                static fn (Release $release): int => $release->amountCentavos() ?? 0,
            ),
            'currency' => 'PHP',
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * Money writes require an `Idempotency-Key`. It is optional everywhere else, and it is not
     * optional here.
     *
     * {@see IdempotencyService} treats a missing key as "no protection, carry on", which is the
     * right default for an ordinary write: a key is a promise a client opts into. On this surface
     * that default is wrong, because the thing an unprotected retry produces is a **second payout
     * to a real family**, and the only safeguard would be the discipline of four independent
     * clients.
     *
     * Refused rather than silently unprotected. A client that forgets the header finds out on its
     * first request in development, not on a bad connection at a payout table.
     *
     * This is a tightening of a published contract, taken now because no client is wired to these
     * endpoints yet — the console still runs on mock adapters — and this is the last moment it
     * costs nothing.
     */
    private function idempotencyKeyOrFail(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Idempotency-Key is required on this request. Generate one when the officer commits '
                .'the intent and send the same key on every retry, so a repeat cannot become a second payout.',
            );
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Release $release, ActorContext $actor): array
    {
        return [
            'id' => $release->uuid,
            'reference_number' => $release->reference_number,
            'resident_id' => $release->resident_id,
            'program_id' => $release->program_id,
            'program_code' => $release->program_code,
            'approval_reference' => $release->approval_reference,
            /*
             * WHO APPROVED IT, and whether that is the person reading this (TAB 08).
             *
             * The server already refuses a self-release outright, so this is not the control — it
             * is what lets a screen say so **before** the officer commits, instead of after. A
             * refusal that arrives only on submit teaches people to submit and see.
             *
             * `self_release` is derived per caller rather than left to the client to compute from
             * `approved_by`, because a client comparing identifiers is a client that can get the
             * comparison wrong, and the consequence of getting it wrong is a warning that does not
             * appear.
             */
            'approved_by' => $release->approved_by,
            'self_release' => $release->approved_by !== null
                && $actor->subjectId !== null
                && (string) $release->approved_by === (string) $actor->subjectId,
            'sequence' => (int) $release->sequence,
            'kind' => $release->kind,
            // Integer centavos plus an explicit currency (conventions §6). Never a formatted
            // string — formatting is the client's, and a server that formats money has decided
            // a locale on somebody's behalf.
            'amount_centavos' => $release->amountCentavos(),
            'currency' => $release->currency,
            'in_kind_description' => $release->in_kind_description,
            'release_mode' => $release->release_mode,
            'funding_source' => $release->funding_source,
            'scheduled_for' => $release->scheduled_for?->toDateString(),
            'release_location' => $release->release_location,
            'status' => $release->status->value,
            'released_at' => $release->released_at?->toIso8601ZuluString(),
            'instrument_reference' => $release->instrument_reference,
            'release_remarks' => $release->release_remarks,
            'acknowledged_by_name' => $release->acknowledged_by_name,
            'acknowledged_relationship' => $release->acknowledged_relationship,
            'acknowledgement_method' => $release->acknowledgement_method,
            'outcome_reason' => $release->outcome_reason,
            'available_transitions' => array_map(
                static fn (ReleaseStatus $status): string => $status->value,
                $release->status->allowedNext(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchProjection(ReleaseBatch $batch): array
    {
        return [
            'id' => $batch->uuid,
            'reference_number' => $batch->reference_number,
            'name' => $batch->name,
            'scheduled_for' => $batch->scheduled_for?->toDateString(),
            'location' => $batch->location,
            'status' => $batch->status,
        ];
    }

    private function releaseOrFail(ActorContext $actor, string $uuid): Release
    {
        /** @var Release|null $release */
        $release = Release::query()->where('uuid', $uuid)->first();

        if ($release === null) {
            throw ResourceNotFoundException::make('That release was not found.');
        }

        $resident = $this->residents->summaryFor((string) $release->resident_id);

        // Out of scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay($actor, $resident?->barangayId, 'That release was not found.');

        return $release;
    }

    private function batchOrFail(string $uuid): ReleaseBatch
    {
        /** @var ReleaseBatch|null $batch */
        $batch = ReleaseBatch::query()->where('uuid', $uuid)->first();

        if ($batch === null) {
            throw ResourceNotFoundException::make('That distribution run was not found.');
        }

        return $batch;
    }

    private function caseOrFail(ActorContext $actor, string $uuid): WelfareCase
    {
        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        $this->authorization->authorizeBarangay($actor, $case->barangay_id, 'That case was not found.');

        return $case;
    }
}
