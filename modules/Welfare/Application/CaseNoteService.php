<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Domain\NoteSensitivity;
use Modules\Welfare\Infrastructure\Eloquent\CaseNote;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * The running record on a case (ADR 0022 §3).
 *
 * THE ACCEPTANCE CRITERION: **notes carry visibility and source classification.**
 *
 * The interesting half is what happens to a note somebody may not read. The body is removed
 * **here, by the application**, not hidden by a client — a payload that never contained the
 * paragraph cannot leak it, and no future refactor of a template can undo that.
 *
 * BUT THE NOTE'S EXISTENCE IS STILL DISCLOSED, along with its author and its time. That is
 * deliberate and it is the part people get wrong: a caseworker who cannot see that three
 * restricted entries exist reads the file as complete and acts as though nothing happened.
 * Knowing a record is there, and that it is not yours to read, is what makes it possible to ask
 * the right person.
 */
final class CaseNoteService
{
    /** A note is worth storing when it says something. Nothing else is enforced. */
    public const MIN_LENGTH = 8;

    public function __construct(private readonly WelfareAudit $audit) {}

    public function add(
        WelfareCase $case,
        string $body,
        NoteSensitivity $sensitivity,
        ActorContext $actor,
    ): CaseNote {
        if (mb_strlen(trim($body)) < self::MIN_LENGTH) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A note needs something in it.');
        }

        /** @var CaseNote $note */
        $note = CaseNote::query()->create([
            'welfare_case_id' => $case->id,
            'sensitivity' => $sensitivity,
            'body' => $body,
            'author_subject_id' => $actor->subjectId,
            'created_at' => now(),
        ]);

        // The action and the sensitivity, never the body. The audit trail must not become a
        // second, less-guarded copy of the notes it describes (Article 5.5).
        $this->audit->record(
            $actor->subjectId,
            'case.note-added',
            'Case note added ('.$sensitivity->value.')',
            (string) $case->uuid,
        );

        return $note;
    }

    /**
     * Withdraws a note. Never deletes one.
     *
     * A note is a contemporaneous record: editing it changes what the file says the office knew
     * at the time, which is the single most useful property it has in a dispute. So a mistake is
     * corrected by a later note, and a withdrawal is a stamp — the fact that something was
     * written and retracted is itself part of the record.
     */
    public function withdraw(CaseNote $note, string $reason, ActorContext $actor): CaseNote
    {
        if ($note->isWithdrawn()) {
            throw new ApiException(ErrorCode::Conflict, 'That note has already been withdrawn.');
        }

        if (trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Say why this note is being withdrawn.');
        }

        /*
         * Only the author may withdraw their own note.
         *
         * A record of what one worker believed at a moment is not another worker's to retract —
         * and a supervisor who disagrees writes their own note, which is a better record than a
         * silently vanished one.
         */
        if ((string) $note->author_subject_id !== (string) $actor->subjectId) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'A note can only be withdrawn by the person who wrote it.',
            );
        }

        $note->forceFill([
            'withdrawn_at' => now(),
            'withdrawn_reason' => $reason,
            'withdrawn_by' => $actor->subjectId,
        ])->save();

        return $note->refresh();
    }

    /**
     * @return Collection<int, CaseNote>
     */
    public function forCase(WelfareCase $case): Collection
    {
        return CaseNote::query()
            ->where('welfare_case_id', $case->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Projects a note for a reader who may or may not be cleared for it.
     *
     * @return array<string, mixed>
     */
    public function disclose(CaseNote $note, bool $mayReadProtected): array
    {
        $cleared = $note->sensitivity === NoteSensitivity::Routine || $mayReadProtected;

        return [
            'id' => $note->uuid,
            'sensitivity' => $note->sensitivity->value,
            'created_at' => $note->created_at?->toIso8601ZuluString(),
            'author_subject_id' => $note->author_subject_id,
            // Removed here, by the application. A payload that never held the paragraph cannot
            // leak it.
            'body' => $cleared ? $note->body : null,
            /*
             * The flag the client renders as "restricted — ask a protection officer".
             *
             * Its presence is the point: the reader learns the record is not complete to them,
             * rather than reading a file that silently omits three entries.
             */
            'is_withheld' => ! $cleared,
            'is_withdrawn' => $note->isWithdrawn(),
            // Shown even on a withheld note: that a note was retracted is a fact about the file
            // rather than about its contents.
            'withdrawn_reason' => $note->withdrawn_reason,
        ];
    }

    /**
     * How many entries this reader is not cleared for.
     *
     * Surfaced as a number so a client can say "3 entries are restricted" in one place rather
     * than rendering three placeholder cards, which reads as clutter and gets designed away.
     *
     * @param  Collection<int, CaseNote>  $notes
     */
    public function withheldCount(Collection $notes, bool $mayReadProtected): int
    {
        if ($mayReadProtected) {
            return 0;
        }

        return $notes->filter(
            static fn (CaseNote $note): bool => $note->sensitivity === NoteSensitivity::Protected,
        )->count();
    }
}
