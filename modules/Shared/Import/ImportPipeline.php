<?php

declare(strict_types=1);

namespace Modules\Shared\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Stage, validate, dry-run and commit a legacy import (ADR 0040 §3).
 *
 * **THE DRY RUN IS NOT A MODE. IT IS THE FIRST HALF.**
 *
 * `validate()` always runs and always writes nothing to the registry. `commit()` is a separate
 * call, refuses a batch that has not been validated, and refuses one with any rejection. So there
 * is no argument to get wrong, no flag to forget — the only way to write is to ask for it, having
 * already seen the report.
 *
 * That shape exists because of the failure it replaces. A script that reads a file and writes
 * residents works on the sample and fails on row 4,812 of the real one, having already written
 * 4,811 residents nobody can now distinguish from the ones that were there before. Separating
 * reading from committing means a bad file is rejected while it is still a file.
 *
 * ── CHUNKED, AND TRANSACTIONAL PER CHUNK ──────────────────────────────────────────────
 *
 * One transaction around forty thousand rows holds locks for minutes and, if it fails, rolls back
 * an hour of work. One transaction per row leaves a half-imported registry when the process dies.
 * A chunk is the compromise, and the rollback plan below is what makes it honest.
 *
 * ── ROLLBACK ──────────────────────────────────────────────────────────────────────────
 *
 * Every committed row records the entity it created (`created_entity_id`), so an import can be
 * undone by walking the batch rather than by guessing which records arrived when. That is a
 * *compensating* plan rather than a database rollback: by the time somebody wants it, the
 * transaction is long closed and a caseworker may already have edited one of the records — which
 * is why `rollbackPlan()` reports what it would touch rather than doing it.
 */
final class ImportPipeline
{
    /**
     * Rows per transaction.
     *
     * Small enough that a failure loses seconds of work and a lock is never held long; large
     * enough that the per-transaction overhead does not dominate. Tuned by measurement when there
     * is a real file, not before.
     */
    private const CHUNK = 500;

    /** How many rejections a report carries. See `ImportOutcome`. */
    private const REJECTION_SAMPLE = 20;

    /** @var array<string, RowMapper> */
    private array $mappers = [];

    /**
     * @param  iterable<RowMapper>  $mappers
     */
    public function __construct(iterable $mappers = [])
    {
        foreach ($mappers as $mapper) {
            $this->mappers[$mapper->target()] = $mapper;
        }
    }

    /**
     * Lands a file's rows in staging. Writes nothing to the registry.
     *
     * @param  iterable<int, array<string, mixed>>  $rows  keyed by source line number
     */
    public function stage(
        string $target,
        string $sourceLabel,
        iterable $rows,
        ?string $requestedBy = null,
        ?string $filename = null,
    ): string {
        $batchUuid = (string) Str::uuid7();

        $batchId = DB::table('import_batches')->insertGetId([
            'uuid' => $batchUuid,
            'target' => $target,
            'source_label' => $sourceLabel,
            'source_filename' => $filename,
            'status' => 'received',
            'requested_by' => $requestedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $total = 0;
        $buffer = [];

        foreach ($rows as $line => $row) {
            $buffer[] = [
                'uuid' => (string) Str::uuid7(),
                'import_batch_id' => $batchId,
                'source_line' => (int) $line,
                // Verbatim and opaque. Evidence of what arrived (ADR 0008 §13 allow-list).
                'source_payload' => (string) json_encode($row),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $total++;

            if (count($buffer) >= self::CHUNK) {
                DB::table('import_rows')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('import_rows')->insert($buffer);
        }

        DB::table('import_batches')->where('id', $batchId)->update([
            'total_rows' => $total,
            'updated_at' => now(),
        ]);

        return $batchUuid;
    }

    /**
     * The dry run. Validates every staged row and reports; **writes nothing to the registry.**
     */
    public function validate(string $batchUuid): ImportOutcome
    {
        $batch = $this->batchOrFail($batchUuid);
        $mapper = $this->mapperFor((string) $batch->target);

        DB::table('import_batches')->where('id', $batch->id)->update([
            'status' => 'validating',
            'updated_at' => now(),
        ]);

        $counts = ['valid' => 0, 'rejected' => 0, 'duplicate' => 0];
        $rejections = [];

        /*
         * KEYS SEEN WITHIN THIS BATCH, as well as keys already in the database. A file that
         * contains the same household twice is the common case — an export joined across two
         * tables — and a duplicate check that only looked at what was already imported would let
         * the second copy through on the first run.
         */
        $seen = [];

        DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->orderBy('source_line')
            ->chunkById(self::CHUNK, function ($rows) use ($mapper, &$counts, &$rejections, &$seen, $batch): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode((string) $row->source_payload, true) ?: [];

                    $reasons = $mapper->validate($payload);

                    if ($reasons !== []) {
                        $counts['rejected']++;

                        if (count($rejections) < self::REJECTION_SAMPLE) {
                            $rejections[] = ['line' => (int) $row->source_line, 'reasons' => $reasons];
                        }

                        $this->markRow($row->id, 'rejected', implode('; ', $reasons));

                        continue;
                    }

                    $key = $mapper->importKey($payload);

                    if ($key !== null && ($this->alreadyImported($key, (int) $batch->id) || isset($seen[$key]))) {
                        $counts['duplicate']++;
                        $this->markRow($row->id, 'duplicate', null, $key);

                        continue;
                    }

                    if ($key !== null) {
                        $seen[$key] = true;
                    }

                    $counts['valid']++;
                    $this->markRow($row->id, 'valid', null, $key);
                }
            });

        DB::table('import_batches')->where('id', $batch->id)->update([
            'status' => 'validated',
            'valid_rows' => $counts['valid'],
            'rejected_rows' => $counts['rejected'],
            'duplicate_rows' => $counts['duplicate'],
            'validated_at' => now(),
            'updated_at' => now(),
        ]);

        return new ImportOutcome(
            (int) $batch->total_rows,
            $counts['valid'],
            $counts['rejected'],
            $counts['duplicate'],
            0,
            $rejections,
            wasDryRun: true,
        );
    }

    /**
     * Writes the valid rows.
     *
     * @param  callable(array<string, mixed>): string  $create  returns the created entity's UUID
     */
    public function commit(string $batchUuid, callable $create): ImportOutcome
    {
        $batch = $this->batchOrFail($batchUuid);

        /*
         * TWO REFUSALS, BOTH DELIBERATE.
         *
         * A batch that has not been validated cannot be committed, because nobody has seen the
         * report. And a batch with any rejection cannot be committed, because importing the good
         * rows and leaving the bad ones produces a partial registry that looks complete — and the
         * missing people are the ones whose data was messiest, which correlates with the
         * households that need the office most.
         */
        if ((string) $batch->status !== 'validated') {
            throw new ApiException(
                ErrorCode::Conflict,
                'This batch has not been validated. Run the dry run and read the report first.',
            );
        }

        if ((int) $batch->rejected_rows > 0) {
            throw new ApiException(
                ErrorCode::Conflict,
                sprintf('This batch has %d rejected rows. Fix the file and re-stage it.', $batch->rejected_rows),
            );
        }

        DB::table('import_batches')->where('id', $batch->id)->update([
            'status' => 'importing',
            'updated_at' => now(),
        ]);

        $imported = 0;

        DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->where('status', 'valid')
            ->orderBy('source_line')
            ->chunkById(self::CHUNK, function ($rows) use ($create, &$imported): void {
                /*
                 * ONE TRANSACTION PER CHUNK. A single transaction over forty thousand rows holds
                 * locks for minutes and loses an hour of work on failure; one per row leaves a
                 * half-imported registry when the process dies. The chunk bounds both.
                 */
                DB::transaction(function () use ($rows, $create, &$imported): void {
                    foreach ($rows as $row) {
                        /** @var array<string, mixed> $payload */
                        $payload = json_decode((string) $row->source_payload, true) ?: [];

                        $entityId = $create($payload);

                        DB::table('import_rows')->where('id', $row->id)->update([
                            'status' => 'imported',
                            // What it became. This is what makes the rollback plan possible.
                            'created_entity_id' => $entityId,
                            'updated_at' => now(),
                        ]);

                        $imported++;
                    }
                });
            });

        DB::table('import_batches')->where('id', $batch->id)->update([
            'status' => 'completed',
            'imported_rows' => $imported,
            'committed_at' => now(),
            'updated_at' => now(),
        ]);

        return new ImportOutcome(
            (int) $batch->total_rows,
            (int) $batch->valid_rows,
            0,
            (int) $batch->duplicate_rows,
            $imported,
            [],
            wasDryRun: false,
        );
    }

    /**
     * What undoing this import would touch. **Reports; does not act.**
     *
     * By the time somebody wants an import undone the transaction is long closed and a caseworker
     * may already have edited one of the records — so an automatic reversal would silently discard
     * real work. This produces the list; a human decides.
     *
     * @return array<string, mixed>
     */
    public function rollbackPlan(string $batchUuid): array
    {
        $batch = $this->batchOrFail($batchUuid);

        $entities = DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->where('status', 'imported')
            ->whereNotNull('created_entity_id')
            ->pluck('created_entity_id')
            ->all();

        return [
            'batch' => $batch->uuid,
            'target' => $batch->target,
            'committed_at' => $batch->committed_at,
            'entities' => $entities,
            'count' => count($entities),
            'note' => 'These records were created by this import. Review each before removing any: '
                .'one may have been edited since, and an automatic reversal would discard that work.',
        ];
    }

    private function alreadyImported(string $key, int $exceptBatchId): bool
    {
        return DB::table('import_rows')
            ->where('import_key', $key)
            ->where('import_batch_id', '!=', $exceptBatchId)
            ->whereIn('status', ['valid', 'imported'])
            ->exists();
    }

    private function markRow(int $id, string $status, ?string $reasons = null, ?string $key = null): void
    {
        DB::table('import_rows')->where('id', $id)->update(array_filter([
            'status' => $status,
            'rejection_reasons' => $reasons === null ? null : Str::limit($reasons, 1000, ''),
            'import_key' => $key,
            'updated_at' => now(),
        ], static fn (mixed $value, string $column): bool => $value !== null || $column === 'rejection_reasons', ARRAY_FILTER_USE_BOTH));
    }

    private function mapperFor(string $target): RowMapper
    {
        if (! isset($this->mappers[$target])) {
            /*
             * No mapper means no import, and the message says so plainly. There is deliberately no
             * fallback that "does its best" with an unknown target — a best-effort import into a
             * resident registry is the thing this whole pipeline exists to prevent.
             */
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('No import mapper is registered for [%s].', $target),
            );
        }

        return $this->mappers[$target];
    }

    private function batchOrFail(string $batchUuid): object
    {
        $batch = DB::table('import_batches')->where('uuid', $batchUuid)->first();

        if ($batch === null) {
            throw new ApiException(ErrorCode::NotFound, 'That import batch was not found.');
        }

        return $batch;
    }
}
