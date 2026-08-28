<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceCatalog;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that rewrites barangay criteria from auto-increment ids to codes.
 *
 * **THIS IS THE PART THAT TOUCHES DATA SOMEBODY IS RELYING ON.** The controller change refuses new
 * bad criteria and the fact change fixes what is compared, but neither of those helps a criterion
 * already stored as `2`. If this rewrite is wrong, a live programme silently changes who it covers
 * — which is the failure the whole change exists to prevent, arriving from the fix instead of the
 * defect.
 *
 * The migration is exercised directly rather than through `artisan migrate`, because the suite has
 * already migrated by the time a test runs. Requiring the file returns the anonymous class.
 */
final class BarangayCriteriaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_18_220000_move_barangay_criteria_to_codes.php');
    }

    private function barangay(string $code, string $name): int
    {
        return (int) DB::table('barangays')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function program(): int
    {
        // Through the model, so this fixture keeps working when the table gains a column. Built by
        // hand first, and it cost three runs to the NOT NULL constraints one at a time.
        return (int) Program::query()->create([
            'code' => 'MIG',
            'name' => 'Migration fixture',
            'owner_office' => 'MSWDO',
            'service_type' => 'financial',
            'benefit_type' => 'cash',
            'status' => 'draft',
            'is_citizen_visible' => false,
            'eligibility_guidance_version' => '1',
        ])->id;
    }

    private function criterion(int $programId, string $code, string $fact, string $value): int
    {
        return (int) DB::table('program_eligibility_criteria')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'program_id' => $programId,
            'code' => $code,
            'fact' => $fact,
            'comparator' => 'is',
            'value' => $value,
            'citizen_explanation' => 'Because.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function valueOf(int $id): string
    {
        return (string) DB::table('program_eligibility_criteria')->where('id', $id)->value('value');
    }

    #[Test]
    public function an_id_becomes_the_barangay_code(): void
    {
        $sanJuan = $this->barangay('brgy-san-juan', 'San Juan');
        $program = $this->program();
        $criterion = $this->criterion($program, 'lives-here', 'barangay', (string) $sanJuan);

        $this->migration()->up();

        $this->assertSame('brgy-san-juan', $this->valueOf($criterion));
    }

    #[Test]
    public function every_entry_in_a_list_is_rewritten(): void
    {
        $sanJuan = $this->barangay('brgy-san-juan', 'San Juan');
        $dolores = $this->barangay('brgy-dolores', 'Dolores');
        $program = $this->program();
        $criterion = $this->criterion($program, 'lives-here', 'barangay', "{$sanJuan}|{$dolores}");

        $this->migration()->up();

        // The separator survives, because `is-one-of` splits on it. A rewrite that joined with a
        // comma would leave one criterion naming a barangay called "brgy-san-juan,brgy-dolores".
        $this->assertSame('brgy-san-juan|brgy-dolores', $this->valueOf($criterion));
    }

    #[Test]
    public function a_criterion_on_another_fact_is_left_alone(): void
    {
        $this->barangay('brgy-san-juan', 'San Juan');
        $program = $this->program();

        // `age is 1` must not become `age is brgy-san-juan` because 1 happens to be a barangay id.
        // The migration filters on the fact, and this is what proves it does.
        $criterion = $this->criterion($program, 'age', 'age', '1');

        $this->migration()->up();

        $this->assertSame('1', $this->valueOf($criterion));
    }

    #[Test]
    public function an_id_with_no_barangay_is_left_exactly_as_it_was(): void
    {
        $this->barangay('brgy-san-juan', 'San Juan');
        $program = $this->program();
        $criterion = $this->criterion($program, 'lives-here', 'barangay', '99999');

        $this->migration()->up();

        /*
         * Left in place rather than dropped or replaced. Dropping it would widen the programme to
         * everybody the criterion used to exclude — a silent grant of eligibility. Replacing it
         * would invent a barangay. Left alone it matches nobody and reads `not-met`, which is what
         * it already did and is visible to anybody reading the rule.
         */
        $this->assertSame('99999', $this->valueOf($criterion));
    }

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $sanJuan = $this->barangay('brgy-san-juan', 'San Juan');
        $program = $this->program();
        $criterion = $this->criterion($program, 'lives-here', 'barangay', (string) $sanJuan);

        $this->migration()->up();
        $this->migration()->up();

        // A code is not all digits, so the second pass cannot touch it. Without that guard a code
        // that looked numeric would be re-read as an id on every deploy.
        $this->assertSame('brgy-san-juan', $this->valueOf($criterion));
    }

    #[Test]
    public function down_restores_the_ids_this_database_holds(): void
    {
        $sanJuan = $this->barangay('brgy-san-juan', 'San Juan');
        $dolores = $this->barangay('brgy-dolores', 'Dolores');
        $program = $this->program();
        $criterion = $this->criterion($program, 'lives-here', 'barangay', "{$sanJuan}|{$dolores}");

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame("{$sanJuan}|{$dolores}", $this->valueOf($criterion));
    }
}
