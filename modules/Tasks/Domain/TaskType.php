<?php

declare(strict_types=1);

namespace Modules\Tasks\Domain;

/**
 * What kind of work a task represents.
 *
 * The vocabulary the master command lists, which is also the set of things this backend already
 * knows how to notice. A type that nothing can raise and nobody can act on is a dropdown entry,
 * not a workflow.
 */
enum TaskType: string
{
    case ReviewIntake = 'review-intake';
    case VerifyResident = 'verify-resident';
    case RequestRequirements = 'request-requirements';
    case CompleteAssessment = 'complete-assessment';
    case ReferralFollowUp = 'referral-follow-up';
    case FieldVisit = 'field-visit';
    case Recommendation = 'recommendation';
    case PrepareRelease = 'prepare-release';
    case ConfirmRelease = 'confirm-release';
    case CloseCase = 'close-case';
    case ResolveDuplicate = 'resolve-duplicate';

    /**
     * Work that belongs to no record.
     *
     * The master command's list is introduced with "include", and every entry in it is
     * case-linked — but the schema deliberately allows a task with no subject, because "ring the
     * barangay about the distribution venue" is real work that nothing in this system holds a row
     * for.
     *
     * Without this, that task gets filed under whichever listed type is closest, and the type
     * column stops meaning anything.
     */
    case General = 'general';

    /**
     * The default team that owes this kind of work.
     *
     * A default, not a rule — a task can be reassigned to anybody. It exists so an automatically
     * raised task lands in *a* queue rather than in none: an unassigned task with no team is
     * invisible to every view except "everything", which is the view nobody opens.
     */
    public function defaultTeam(): string
    {
        return match ($this) {
            self::PrepareRelease, self::ConfirmRelease => 'disbursement',
            self::VerifyResident, self::ResolveDuplicate => 'registry',
            default => 'casework',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
