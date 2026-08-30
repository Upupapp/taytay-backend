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

printf("%d endpoint/field pairs observed; %d fields never once non-null.\n\n", count($seen), count($alwaysNull));

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
