<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Contracts\DocumentVersionView;
use Modules\ServiceCatalog\Application\ProgramCatalog;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Domain\RequirementApplicability;
use Modules\Welfare\Domain\RequirementObligation;
use Modules\Welfare\Infrastructure\Eloquent\CaseRequirement;
use Modules\Welfare\Infrastructure\Eloquent\DocumentRequest;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * What a case still owes (ADR 0020 §6).
 *
 * The owner of the requirement slots, and therefore the only path between a case and the document
 * store. Files stores and versions; this class holds the case-shaped rules — which requirement a
 * document belongs to, whether a ruled-out slot may be filled, and when the office's request for
 * a document has been answered.
 *
 * It speaks to Files only through {@see DocumentLibrary}, in UUIDs and published views. It holds
 * no Files model, so it cannot reach a file's bytes and cannot rewrite a version's history even
 * by mistake (Article 2.1).
 */
final class CaseRequirementService
{
    /** The `owner_type` under which case requirement documents are filed in the Files module. */
    public const OWNER_TYPE = 'welfare.case-requirement';

    public function __construct(
        private readonly DocumentLibrary $library,
        private readonly ProgramCatalog $programs,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Copies a programme's requirement template onto a case.
     *
     * COPIED, NOT REFERENCED. A programme's requirement list is versioned and changes; a case
     * must stay explicable against the list in force when it was opened. Reading through to the
     * live template would silently rewrite what an applicant was asked for, so a case approved
     * under three requirements would later appear to have skipped a fourth that did not exist at
     * the time (the same reasoning as the pinned guidance version in ADR 0018).
     *
     * Idempotent: re-running adds what is missing and never disturbs a slot that holds a
     * document.
     *
     * @return Collection<int, CaseRequirement>
     */
    public function attachTemplate(WelfareCase $case, string $programUuid, ActorContext $actor): Collection
    {
        $program = $this->programs->findByUuid($programUuid);

        if ($program === null) {
            throw new ApiException(ErrorCode::NotFound, 'That programme was not found.');
        }

        return DB::transaction(function () use ($case, $program): Collection {
            foreach ($this->programs->currentRequirements($program) as $requirement) {
                CaseRequirement::query()->firstOrCreate(
                    [
                        'welfare_case_id' => $case->id,
                        'requirement_code' => (string) $requirement->code,
                    ],
                    [
                        'label' => (string) $requirement->label,
                        'template_version' => (string) $requirement->template_version,
                        'obligation' => (string) $requirement->obligation,
                        'citizen_instructions' => $requirement->citizen_instructions,
                        // A conditional requirement starts undecided, which is a state somebody
                        // owes an answer to — not a quiet "probably not needed".
                        'applicability' => RequirementApplicability::Undecided,
                    ],
                );
            }

            return $this->forCase($case);
        });
    }

    /**
     * @return Collection<int, CaseRequirement>
     */
    public function forCase(WelfareCase $case): Collection
    {
        return CaseRequirement::query()
            ->where('welfare_case_id', $case->id)
            ->orderBy('obligation')
            ->orderBy('requirement_code')
            ->get();
    }

    /**
     * Records a document against a requirement, appending a version.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDocument(
        CaseRequirement $requirement,
        ?string $fileUuid,
        array $attributes,
        ActorContext $actor,
    ): DocumentVersionView {
        /*
         * A requirement ruled out cannot then be filled.
         *
         * Otherwise a document sits against a requirement the office decided did not apply, and
         * the case file says two contradictory things about one slot with no indication of which
         * is current. Ruling it back in is a recorded decision, and then the document is welcome.
         */
        if ($requirement->applicability === RequirementApplicability::DoesNotApply) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That requirement was ruled not to apply. Rule it back in before recording a document.',
            );
        }

        return DB::transaction(function () use ($requirement, $fileUuid, $attributes, $actor): DocumentVersionView {
            $documentUuid = $this->library->slotFor(
                self::OWNER_TYPE,
                (string) $requirement->uuid,
                (string) ($attributes['document_type'] ?? $requirement->requirement_code),
            );

            $version = $this->library->append($documentUuid, $fileUuid, $attributes, $actor);

            if ($requirement->document_id === null) {
                $requirement->forceFill(['document_id' => $documentUuid])->save();
            }

            $this->closeAnsweredRequests($requirement);

            $this->audit->record(
                $actor->subjectId,
                'case.document-recorded',
                'Document recorded against '.$requirement->requirement_code,
                $this->caseUuid($requirement),
            );

            return $version;
        });
    }

    /**
     * Rules a conditional requirement in or out.
     *
     * The reason is mandatory in BOTH directions. Deciding somebody does not need a document is
     * the step that can waive a safeguard, and "does-not-apply" with no author and no reason is
     * indistinguishable from an oversight.
     */
    public function decideApplicability(
        CaseRequirement $requirement,
        RequirementApplicability $applicability,
        string $reason,
        ActorContext $actor,
    ): CaseRequirement {
        if ($requirement->obligation !== RequirementObligation::Conditional) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Only a conditional requirement can be ruled in or out.',
            );
        }

        if (! $applicability->isDecided()) {
            throw new ApiException(ErrorCode::BadRequest, 'That is not a decision.');
        }

        if (trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Say why this requirement does or does not apply.');
        }

        $requirement->forceFill([
            'applicability' => $applicability,
            'applicability_reason' => $reason,
            'applicability_decided_by' => $actor->subjectId,
            'applicability_decided_at' => now(),
        ])->save();

        $this->audit->record(
            $actor->subjectId,
            'case.requirement-applicability',
            'Requirement '.$requirement->requirement_code.' ruled '.$applicability->value,
            $this->caseUuid($requirement),
        );

        return $requirement->refresh();
    }

    public function currentVersion(CaseRequirement $requirement): ?DocumentVersionView
    {
        return $this->library->currentVersion($requirement->document_id);
    }

    /**
     * Whether a requirement is satisfied — a current, verified, unexpired document sits in it.
     *
     * VERIFIED, NOT MERELY PRESENT. A document somebody handed over is not yet a document the
     * office accepted, and treating receipt as satisfaction would let a case reach approval on
     * papers nobody read.
     */
    public function isSatisfied(CaseRequirement $requirement): bool
    {
        return $this->satisfiedBy($this->currentVersion($requirement));
    }

    public function isOutstanding(CaseRequirement $requirement): bool
    {
        return $this->outstandingGiven($requirement, $this->currentVersion($requirement));
    }

    /**
     * The current version of each requirement's document, keyed by REQUIREMENT id.
     *
     * The batch form, for a caller rendering a list. `currentVersion()` costs three queries and
     * a requirements projection asked it four times per row — the version, then `isSatisfied()`
     * re-asking, then `isOutstanding()` re-asking through `isSatisfied()`, then `outstandingFor()`
     * doing the whole thing again for the count. Twelve queries per requirement, measured
     * (ADR 0042 §6).
     *
     * An absent key means that requirement has no live document, which is the normal state of a
     * slot nobody has filled — not a lookup to retry per row.
     *
     * @param  Collection<int, CaseRequirement>  $requirements
     * @return array<string, DocumentVersionView>
     */
    public function currentVersionsFor(Collection $requirements): array
    {
        $byDocument = $this->library->currentVersionsFor(
            $requirements->pluck('document_id')->filter()->map(strval(...))->values()->all(),
        );

        $byRequirement = [];

        foreach ($requirements as $requirement) {
            $version = $byDocument[(string) $requirement->document_id] ?? null;

            if ($version !== null) {
                $byRequirement[(string) $requirement->uuid] = $version;
            }
        }

        return $byRequirement;
    }

    /**
     * The satisfaction rule, applied to a version somebody has ALREADY resolved.
     *
     * This is where the rule lives; `isSatisfied()` is the same rule with the lookup attached.
     * Two entry points, one definition — a list path that reimplemented "verified and unexpired"
     * inline would drift from the single-row path the moment either changed.
     */
    public function satisfiedBy(?DocumentVersionView $version): bool
    {
        return $version?->satisfies() ?? false;
    }

    public function outstandingGiven(CaseRequirement $requirement, ?DocumentVersionView $version): bool
    {
        return $requirement->obligation->isOutstanding(
            $requirement->applicability,
            $this->satisfiedBy($version),
        );
    }

    /**
     * The requirements still standing between this case and a decision.
     *
     * @return Collection<int, CaseRequirement>
     */
    public function outstandingFor(WelfareCase $case): Collection
    {
        $requirements = $this->forCase($case);
        $versions = $this->currentVersionsFor($requirements);

        return $requirements
            ->filter(fn (CaseRequirement $requirement): bool => $this->outstandingGiven(
                $requirement,
                $versions[(string) $requirement->uuid] ?? null,
            ))
            ->values();
    }

    /**
     * A document arriving answers whatever the office asked for in that slot.
     *
     * Closed automatically rather than by a second staff action: a clerk who has just recorded
     * the certificate should not also have to remember to tick off the request that asked for
     * it, and a request left open after it was answered is what makes an overdue queue stop
     * being believed.
     */
    private function closeAnsweredRequests(CaseRequirement $requirement): void
    {
        DocumentRequest::query()
            ->where('welfare_case_requirement_id', $requirement->id)
            ->where('state', 'open')
            ->update(['state' => 'answered', 'closed_at' => now()]);
    }

    private function caseUuid(CaseRequirement $requirement): string
    {
        return (string) WelfareCase::query()->whereKey($requirement->welfare_case_id)->value('uuid');
    }
}
