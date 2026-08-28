<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the parts of CLAUDE.md Article 9 that must not quietly move.
 *
 * ── WHY A GOVERNANCE DOCUMENT NEEDS A TEST ───────────────────────────────────────────
 *
 * **A weakened safety line reads as a wording preference in review.** On 2026-08-28 the
 * equivalent sentence in `taytay-mobile-app` was softened from *"a push is a publication"* to
 * *"pushing is routine"* — one clause, no diff noise, and it changes a warning into a
 * normalisation while keeping the word "public" attached to nothing. It never reached a commit,
 * because that repository asserts its own constitution in
 * `test/core/release_readiness_test.dart`. This repository had no equivalent and would have
 * taken the same edit silently. This file is that gap closed.
 *
 * ── WHAT IS ASSERTED, AND WHAT DELIBERATELY IS NOT ───────────────────────────────────
 *
 * **The invariants, never the whole document.** Asserting the full text would make every
 * legitimate amendment a failure, and a test that fails on correct work is one people learn to
 * edit rather than read. Article 9 was itself amended on 2026-08-28 — pushing to `main` is now
 * authorised (ADR 0046) — and that amendment is not asserted here, precisely because it is the
 * part that was allowed to change.
 *
 * What is asserted is what the amendment did NOT touch: the absolute prohibitions, the reason a
 * push here is not a small act, and the obligation to leave somebody else's uncommitted work
 * alone.
 *
 * **If this test goes red after a deliberate amendment, the failure is correct.** The
 * constitution moved and something noticed, which is the whole point of asserting it. Rewrite
 * the expectation to the new invariant and say why in this docblock. Do not delete the
 * assertion, and do not edit the constitution to make a test pass — the file outranks this test
 * (Article 0), and this test exists to make a change to it deliberate rather than incidental.
 */
final class ConstitutionInvariantsTest extends TestCase
{
    /**
     * Forbidden without exception, whatever the push rule happens to be.
     *
     * Pushing changed status on 2026-08-28. Nothing on this list did, and a future amendment
     * that quietly dropped one should fail here.
     */
    private const ABSOLUTE_PROHIBITIONS = [
        'force-push',
        'history rewriting',
        'merging protected',
        'credential rotation',
        'production data',
        'exposing secrets',
    ];

    private function constitution(): string
    {
        $path = dirname(__DIR__, 2).'/CLAUDE.md';

        $this->assertFileExists($path, 'CLAUDE.md is the highest-authority document and is missing.');

        return (string) file_get_contents($path);
    }

    #[Test]
    public function article_9_still_forbids_what_it_has_always_forbidden(): void
    {
        $claudeMd = $this->constitution();

        foreach (self::ABSOLUTE_PROHIBITIONS as $prohibition) {
            $this->assertStringContainsString(
                $prohibition,
                $claudeMd,
                "Article 9 no longer forbids {$prohibition}.",
            );
        }
    }

    #[Test]
    public function the_reason_a_push_here_is_not_a_small_act_is_still_stated(): void
    {
        /*
         * THE EXACT SENTENCE THAT WAS SOFTENED IN THE SIBLING REPOSITORY.
         *
         * This repository is public, so every push publishes resident-facing code and the
         * governance around it. Article 9 authorises the push; this clause is why it is still
         * worth thinking about, and it is the half an edit is most likely to drop because
         * removing it leaves a sentence that still scans.
         */
        $this->assertStringContainsString(
            'a push is a publication',
            $this->constitution(),
            'Article 9 no longer says that a push to this public repository is a publication.',
        );
    }

    #[Test]
    public function somebody_elses_uncommitted_work_is_still_protected(): void
    {
        /*
         * The only protection this rule has anywhere in the programme.
         *
         * `git reset --hard` and `git clean` have no git-level equivalent of the push controls —
         * nothing refuses them the way an unresolvable pushurl refuses a push. In the sibling
         * mobile repository the backstop is a `.claude/settings.json` deny that only applies when
         * a session is rooted in that directory, and every session on this machine runs a level
         * above it. Here the rule exists in this sentence and nowhere else.
         */
        $this->assertStringContainsString(
            'never reset, clean or revert',
            $this->constitution(),
            "Article 9 no longer forbids resetting or cleaning somebody else's uncommitted work.",
        );
    }

    #[Test]
    public function the_constitution_still_claims_authority_over_everything_else(): void
    {
        /*
         * Article 0. Without this line the document is a style guide, and every other assertion
         * in this file is asserting the contents of a suggestion.
         */
        $this->assertStringContainsString(
            'highest-authority document',
            $this->constitution(),
            'CLAUDE.md no longer claims to outrank other instructions in this repository.',
        );
    }
}
