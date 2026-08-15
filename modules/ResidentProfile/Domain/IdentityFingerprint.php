<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Domain;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The deterministic matching key.
 *
 * Reduces a claimed identity to a normalised hash so that "MARIA  dela Cruz" and
 * "maria Dela  Cruz" born on the same day land on the same value, and so that candidate
 * lookup is an indexed equality search rather than a table scan of everybody's names.
 *
 * WHY A HASH RATHER THAN THE NORMALISED STRING: the index would otherwise spell out every
 * resident's name and birth date in a structure that turns up in dumps, replicas and
 * `EXPLAIN` output. The hash matches exactly as well and reads as nothing.
 *
 * WHY DETERMINISTIC RATHER THAN FUZZY (ADR 0010 §3): a similarity score invites automatic
 * merging at some threshold, and a wrong merge in a welfare registry means one person
 * receiving another person's assistance and a second person becoming invisible to the
 * LGU. Exact rules produce candidates; a human decides. Fuzzy comparison belongs in the
 * reviewer's screen, not in the decision.
 */
final class IdentityFingerprint
{
    /**
     * Primary key: family name + given name + birth date.
     *
     * Middle name is excluded on purpose — it is inconsistently recorded across Philippine
     * documents (sometimes the mother's maiden surname, sometimes absent, sometimes an
     * initial), so including it would split the same person across several fingerprints
     * and defeat the matching it exists to enable.
     */
    public static function forName(string $firstName, string $lastName, string|Carbon $birthDate): string
    {
        $date = $birthDate instanceof Carbon ? $birthDate->toDateString() : Carbon::parse($birthDate)->toDateString();

        return hash('sha256', implode('|', [
            self::normalise($lastName),
            self::normalise($firstName),
            $date,
        ]));
    }

    /**
     * Case, accents, punctuation and repeated whitespace all removed.
     *
     * `Ñ` is folded to `n` deliberately: the same person appears as "Peña" and "Pena"
     * across documents depending on which system typed them, and treating those as
     * different people is the commonest cause of a duplicate registry entry here.
     */
    public static function normalise(string $value): string
    {
        $value = Str::ascii(trim($value));
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\s]/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
