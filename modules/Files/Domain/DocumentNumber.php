<?php

declare(strict_types=1);

namespace Modules\Files\Domain;

/**
 * A document number, reduced to what an office actually needs from it.
 *
 * THE MASK IS APPLIED BEFORE STORAGE, NOT BEFORE DISPLAY. The master command asks for a "masked
 * document number" and this takes it literally: the full number is never written to a column,
 * so it is never in a backup, a replica, a database dump, a query log or a support export, and
 * no future endpoint can leak what was never kept.
 *
 * Masking at render time would be a different and weaker thing — the value would exist in every
 * one of those places, and the guarantee would rest on every current and future reader
 * remembering to mask. That is the arrangement the admin console has today, where
 * `maskDocumentNumber` is applied in the view over a `documentNumber: string | null` the client
 * was sent in full (gap G-24). This backend does not send it, so the client cannot leak it.
 *
 * FOUR CHARACTERS IS THE WHOLE PURPOSE. A clerk needs to confirm the paper on the screen is the
 * paper in their hand. Four characters does that and cannot reconstruct an identifier — the same
 * limit RA 11055 imposes on the PhilSys reference, applied to every document type rather than
 * only the one the law names.
 *
 * A number short enough that masking would reveal most of it is kept as its length alone.
 */
final class DocumentNumber
{
    /** How many trailing characters survive. */
    public const VISIBLE = 4;

    /**
     * Reduces a number to the four characters that may be stored.
     *
     * Returns null for an empty or absent number, and for one so short that keeping any of it
     * would be keeping most of it.
     */
    public static function lastFour(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        // A four-character number masked to its last four is not masked at all.
        if (mb_strlen($trimmed) <= self::VISIBLE) {
            return null;
        }

        return mb_substr($trimmed, -self::VISIBLE);
    }

    /**
     * How the stored remnant is rendered to a client.
     *
     * The bullets are produced here rather than by each client, so "masked" looks the same on
     * the console, the citizen portal and the mobile app, and so a client cannot accidentally
     * render the bare four characters as though they were the whole number.
     */
    public static function display(?string $lastFour): ?string
    {
        return $lastFour === null ? null : '••••'.$lastFour;
    }
}
