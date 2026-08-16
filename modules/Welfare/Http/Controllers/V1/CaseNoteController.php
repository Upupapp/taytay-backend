<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\CaseNoteService;
use Modules\Welfare\Application\SafeguardingRegistry;
use Modules\Welfare\Domain\NoteSensitivity;
use Modules\Welfare\Infrastructure\Eloquent\CaseNote;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The running record on a case, and its restricted tier (ADR 0022 §3–4).
 *
 * WHAT A READER WITHOUT CLEARANCE GETS: every note's existence, author, sensitivity and time, and
 * a null body on the protected ones.
 *
 * That is not a compromise — it is the design. A caseworker who cannot see that three restricted
 * entries exist reads the file as complete and acts as though nothing happened. Knowing a record
 * is there, and that it is not theirs to read, is what makes it possible to ask the right person.
 *
 * The body is removed **by the application**, so a payload that never contained the paragraph
 * cannot leak it, and no future change to a client can undo that.
 */
final class CaseNoteController
{
    public function __construct(
        private readonly CaseNoteService $notes,
        private readonly SafeguardingRegistry $safeguarding,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);
        $mayReadProtected = $this->authorization->allows($actor, Permission::CaseNoteViewProtected);

        $notes = $this->notes->forCase($model);

        return ApiResponse::item([
            'notes' => $notes->map(
                fn (CaseNote $note): array => $this->notes->disclose($note, $mayReadProtected),
            )->all(),
            // So a client can say "3 entries are restricted" once, rather than rendering three
            // placeholder cards that read as clutter and get designed away.
            'withheld_count' => $this->notes->withheldCount($notes, $mayReadProtected),
            /*
             * Existence only, on a case DETAIL view — never in a list.
             *
             * Somebody opening a file is entitled to know there is a restricted record they
             * cannot read; somebody scrolling a queue is not, because a marker in a list marks
             * the family to every person who scrolls past.
             */
            'has_safeguarding_concern' => $this->safeguarding->hasActiveConcern((string) $model->resident_id),
        ]);
    }

    public function store(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'sensitivity' => ['sometimes', 'string', 'in:'.implode(',', NoteSensitivity::values())],
        ]);

        $sensitivity = NoteSensitivity::from($validated['sensitivity'] ?? 'routine');

        /*
         * Writing into the protected tier needs the same clearance as reading it.
         *
         * Otherwise anybody could file a note nobody in their own team can see, which is a way to
         * put something beyond review rather than beyond disclosure — the opposite of what the
         * tier is for.
         */
        if ($sensitivity === NoteSensitivity::Protected) {
            $this->authorization->authorize($actor, Permission::CaseNoteViewProtected);
        }

        $note = $this->notes->add($model, $validated['body'], $sensitivity, $actor);

        return ApiResponse::created($this->notes->disclose($note, true));
    }

    /**
     * Withdraws a note. Never deletes one.
     */
    public function withdraw(Request $request, ActorContext $actor, string $case, string $note): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);

        $model = $this->caseOrFail($actor, $case);

        /** @var CaseNote|null $row */
        $row = CaseNote::query()
            ->where('uuid', $note)
            ->where('welfare_case_id', $model->id)
            ->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That note was not found.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $withdrawn = $this->notes->withdraw($row, $validated['reason'], $actor);

        return ApiResponse::item($this->notes->disclose(
            $withdrawn,
            $this->authorization->allows($actor, Permission::CaseNoteViewProtected),
        ));
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
