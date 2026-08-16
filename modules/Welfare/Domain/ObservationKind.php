<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * What was recorded on a visit, and **whose claim it is**.
 *
 * THE DISTINCTION IS NOT A FORMATTING PREFERENCE. Three sentences a worker might write in one
 * paragraph:
 *
 *   "The roof is missing sheets over the sleeping area."
 *   "She says her husband has not sent money since March."
 *   "The household appears unable to meet its own food costs."
 *
 * The first is checkable by another visit. The second is a report the office is repeating, and
 * may be wrong without anybody lying. The third is a professional judgement a later reader may
 * disagree with. As one block of prose they are indistinguishable, and six months on a different
 * worker reads all three as established fact about the family — and acts on it.
 *
 * Nothing here prevents a worker recording a judgement. It prevents a judgement from being
 * mistaken for something the family said.
 */
enum ObservationKind: string
{
    /** Something the worker saw or measured at the address. Checkable by another visit. */
    case Observed = 'observed';

    /** What the household told the worker. Recorded as their account, not as verified. */
    case ClientSaid = 'client-said';

    /** What a neighbour, barangay official or relative said. Whose account it is must be named. */
    case ThirdPartySaid = 'third-party-said';

    /** The worker's professional judgement, drawn from the above. */
    case WorkerAssessed = 'worker-assessed';

    public function label(): string
    {
        return match ($this) {
            self::Observed => 'Seen by the worker',
            self::ClientSaid => 'Said by the client',
            self::ThirdPartySaid => 'Said by someone else',
            self::WorkerAssessed => "The worker's assessment",
        };
    }

    /**
     * Whether this kind carries somebody else's words and must name them.
     *
     * "A neighbour said" with no neighbour named is a rumour the office cannot check and cannot
     * answer for — and it is the form in which a grudge enters a family's file.
     */
    public function needsAttribution(): bool
    {
        return $this === self::ThirdPartySaid;
    }

    /** Whether this is the worker's own inference rather than a report of fact. */
    public function isJudgement(): bool
    {
        return $this === self::WorkerAssessed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
