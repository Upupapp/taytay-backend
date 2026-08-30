#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reports response fields that were NEVER once non-null across a whole test run.
 *
 * Usage:
 *
 *     FIELD_PROBE=1 php artisan test          # collect
 *     php tools/field-probe-report.php        # read
 *
 * ── WHAT THIS IS FOR ─────────────────────────────────────────────────────────────────
 *
 * `report_reasons` on the moderation queue was null for every row for as long as the feature
 * existed: the query counted a relation without loading it, and the projection renders the
 * field only when it IS loaded. No error, no slow page, green suite — a moderator simply never
 * saw why anything had been reported.
 *
 * A query budget cannot see that. A silently-null field costs no queries, which is exactly what
 * makes it silent. Nor can a static scan: the field is declared, spelled correctly and present.
 * The only signal is that it is never populated.
 *
 * ── HOW TO READ THE OUTPUT, WHICH MATTERS MORE THAN THE OUTPUT ───────────────────────
 *
 * **"Always null" means "no scenario the suite builds ever populated it". That is TWO different
 * things and the report cannot tell them apart:**
 *
 *   1. a real defect — the field cannot be populated, because a load is missing; or
 *   2. a fixture gap — nothing in the suite creates the data that would fill it.
 *
 * Measured example of (2): `media` was null on all 104 observations of `GET /admin/newsfeed`,
 * while the same projection populated it on the status endpoint. It looked exactly like the
 * moderation defect. It was not — the list query already eager-loads `media`; no test had ever
 * listed a post that HAD any. Confirmed by publishing a post with an image and listing it.
 *
 * **So a name here is a question, not a finding.** Answer it by populating the data and calling
 * the endpoint. If the field fills, the gap is in the fixtures; if it stays null, it is the
 * moderation defect again.
 *
 * The strongest signals are fields whose sibling endpoints DO populate them, which is why the
 * report groups by field as well as by endpoint.
 *
 * ── THE RUNS SO FAR, AND WHAT THEY SETTLED ───────────────────────────────────────────
 *
 * The first full run reported "2160 pairs, 166 fields never once non-null, 106 of them populated
 * on one endpoint and never on a sibling". **The middle number was mislabelled**: 166 was the
 * count of fields null on AT LEAST ONE endpoint. Only 60 were never non-null anywhere, and the
 * other 106 were the asymmetric ones — which the prose already called the strongest signal while
 * the output ranked everything together and cut the list at thirty by raw volume. A
 * six-observation asymmetry therefore sat far below fields null two hundred times. Fixed; the two
 * populations are now counted and printed separately, asymmetries first.
 *
 * **The second run was on PostgreSQL, and found the defect that mislabelling had hidden.**
 * `report_reasons` was non-null on the moderation QUEUE and never once non-null across six
 * observations of the moderation ACTION: `moderatorProjection()` is shared by both, the queue
 * loaded the `reports` relation and the action did not, so the response a moderator received the
 * instant they acted reported zero reports and no reasons for a comment reported twice. The same
 * defect this tool was built for, on the sibling endpoint — the mirrored-pair class of ADR 0047.
 *
 * **The PostgreSQL and SQLite runs are otherwise identical**, to the pair: 35965 observations,
 * 2101 pairs, 60 never-non-null fields, 478 never-non-null pairs, and zero differences either
 * way. This class of defect is not driver-sensitive, which is worth knowing before running it
 * twice again.
 *
 * **Intersected with what this class can actually be, that reduces to two.** A field can only
 * exhibit it if a PROJECTION renders it from a relation that something must have loaded.
 * Everything else in the list is one of:
 *
 *   * input defaulting on a WRITE path (`$attributes['note'] ?? null`) — not a projection;
 *   * an enum read (`$case->status->value`) — cannot be unloaded;
 *   * a plain column (`$referral->outcome`) — if the column is set, the field is set;
 *   * a nullable field no fixture happens to populate.
 *
 * The two that survived were `report_reasons`, which WAS the defect, and `media`, which was
 * checked by publishing a post with an image and listing it — it fills. `report_reasons` needed
 * fixing TWICE, on two endpoints sharing one projection, and the second was found only after the
 * output stopped burying asymmetries.
 *
 * **So run this after adding an endpoint or a projection, not as a routine sweep.** Its value is
 * highest when the code has just changed; on a settled codebase it mostly reports fixtures.
 * `grep -rn "relationLoaded(" modules/` finds the same class in one line and is the cheaper
 * first move — this probe is what catches the case where the guard is a `??` or a relation
 * property rather than an explicit `relationLoaded`.
 */
$path = __DIR__.'/../storage/framework/testing/field-probe.tsv';

if (! is_file($path)) {
    fwrite(STDERR, "No probe data. Run: FIELD_PROBE=1 php artisan test\n");
    exit(1);
}

/** @var array<string, array{0:int,1:int}> $seen */
$seen = [];

foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $parts = explode("\t", $line);

    if (count($parts) !== 3) {
        continue;
    }

    [$endpoint, $field, $nonNull] = $parts;
    $key = $endpoint."\t".$field;
    $seen[$key] ??= [0, 0];
    $seen[$key][1]++;

    if ($nonNull === '1') {
        $seen[$key][0]++;
    }
}

$alwaysNull = [];

foreach ($seen as $key => [$nonNull, $total]) {
    if ($nonNull === 0) {
        [$endpoint, $field] = explode("\t", $key);
        $alwaysNull[$field][] = [$endpoint, $total];
    }
}

/*
 * THE ASYMMETRIC ONES FIRST, because that is the defect's shape and the flat list buried it.
 *
 * A field that is populated on one endpoint and never on another is a much sharper question than
 * one that is null everywhere: the second is usually a fixture gap, the first means two
 * projections of the same thing disagree. `report_reasons` was exactly this -- non-null on the
 * moderation QUEUE and never once non-null across six observations of the moderation ACTION,
 * because the queue loaded the relation and the action did not.
 *
 * It was findable in the old output and nobody found it, which is the point. The headline said
 * "fields never once non-null" while counting fields null on AT LEAST ONE endpoint -- two
 * different numbers, 60 and 166 -- and the list was cut at the top 30 by raw observation count,
 * so a six-observation asymmetry sat far below the fold behind fields null two hundred times.
 * Volume is not severity.
 */
$populatedSomewhere = [];

foreach ($seen as $key => [$nonNull, $total]) {
    if ($nonNull > 0) {
        [, $field] = explode("\t", $key);
        $populatedSomewhere[$field] = true;
    }
}

$asymmetric = array_intersect_key($alwaysNull, $populatedSomewhere);
$neverAnywhere = array_diff_key($alwaysNull, $populatedSomewhere);

printf(
    "%d endpoint/field pairs observed.\n  %d fields never non-null ANYWHERE.\n  %d fields populated on one endpoint and never on another.\n\n",
    count($seen),
    count($neverAnywhere),
    count($asymmetric),
);

if ($asymmetric !== []) {
    echo "── populated elsewhere, never here — read these first ──\n\n";

    uasort($asymmetric, static fn (array $a, array $b): int => array_sum(array_column($b, 1)) <=> array_sum(array_column($a, 1)));

    foreach ($asymmetric as $field => $rows) {
        printf("  %-28s null on %d endpoint(s)\n", $field, count($rows));

        foreach ($rows as [$endpoint, $total]) {
            printf("      %-52s %dx\n", $endpoint, $total);
        }
    }

    echo "\n── null everywhere the suite looked ──\n\n";
}

$alwaysNull = $neverAnywhere;

/*
 * Sorted by how often the field was OBSERVED null, because a field null across two hundred
 * calls is a much stronger question than one null across two.
 */
uasort($alwaysNull, static fn (array $a, array $b): int => array_sum(array_column($b, 1)) <=> array_sum(array_column($a, 1)));

foreach (array_slice($alwaysNull, 0, 30, true) as $field => $rows) {
    printf("  %-28s %d observations\n", $field, array_sum(array_column($rows, 1)));

    foreach (array_slice($rows, 0, 3) as [$endpoint, $total]) {
        printf("      %-52s %dx\n", $endpoint, $total);
    }
}
