<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The five barangays of Taytay, Rizal.
 *
 * Reference data, not fixture data: this is the real jurisdiction list and it belongs in
 * every environment, including production. It is a seeder rather than part of the
 * migration so the schema step stays schema-only (ADR 0008 §14).
 *
 * Idempotent — re-running updates names in place and inserts nothing twice, so it is safe
 * to run on every deploy.
 *
 * `psgc_code` is deliberately left null. The authoritative PSA Philippine Standard
 * Geographic Code dataset has not been loaded (gap G-11), and a guessed code is worse than
 * an absent one because DSWD statutory reporting keys off it. Filling these in is a
 * prerequisite for certifying any statutory report as correct.
 */
final class BarangaySeeder extends Seeder
{
    /** @var list<array{code: string, name: string}> */
    private const BARANGAYS = [
        ['code' => 'brgy-dolores', 'name' => 'Dolores'],
        ['code' => 'brgy-muzon', 'name' => 'Muzon'],
        ['code' => 'brgy-san-isidro', 'name' => 'San Isidro'],
        ['code' => 'brgy-san-juan', 'name' => 'San Juan'],
        ['code' => 'brgy-santa-ana', 'name' => 'Santa Ana'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::BARANGAYS as $barangay) {
            $row = DB::table('barangays')->where('code', $barangay['code']);

            if ($row->exists()) {
                // Never reassign the uuid: it is the identifier clients already hold.
                $row->update(['name' => $barangay['name'], 'updated_at' => $now]);

                continue;
            }

            DB::table('barangays')->insert([
                'uuid' => (string) Str::uuid7(),
                'code' => $barangay['code'],
                'name' => $barangay['name'],
                'psgc_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
