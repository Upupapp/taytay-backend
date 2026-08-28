<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\BarangayCodes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The barangay id → code map that keeps the auto-increment key out of responses (L-15).
 *
 * The unknown-id branch is tested HERE rather than through an endpoint, and that is a correction
 * to how this was first written. The feature test tried to produce a dangling reference by
 * deleting a barangay a resident points at, and the database refused it: `residents.barangay_id`
 * is `restrictOnDelete`, and the nullable references elsewhere are `nullOnDelete`, so they become
 * NULL rather than dangling. **The schema makes that state unreachable.**
 *
 * The branch is therefore defensive, not a scenario — so it is proven at the unit that owns it,
 * and the test says which it is. A feature test asserting an impossible state would have read as
 * coverage of a real case.
 */
final class BarangayCodesTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function it_resolves_a_barangay_to_its_published_code(): void
    {
        $id = $this->barangay('brgy-san-juan', 'San Juan');

        $this->assertSame('brgy-san-juan', (new BarangayCodes)->codeFor($id));
    }

    #[Test]
    public function a_null_reference_resolves_to_null(): void
    {
        $this->assertNull((new BarangayCodes)->codeFor(null));
    }

    #[Test]
    public function an_unknown_id_resolves_to_null_and_never_to_the_number(): void
    {
        $this->barangay('brgy-san-juan', 'San Juan');

        /*
         * NULL, NOT THE INTEGER. A fallback to the id would be the tidy-looking choice and would
         * put the auto-increment key straight back into the field created to keep it out.
         */
        $this->assertNull((new BarangayCodes)->codeFor(99999));
    }

    #[Test]
    public function the_map_is_read_once_however_many_lookups_are_made(): void
    {
        $a = $this->barangay('brgy-san-juan', 'San Juan');
        $b = $this->barangay('brgy-dolores', 'Dolores');

        $codes = new BarangayCodes;
        $codes->codeFor($a);

        DB::enableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $codes->codeFor($a);
            $codes->codeFor($b);
        }
        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        /*
         * The whole reason this is a map and not a lookup. A per-row query here would show up as
         * an N+1 on every list endpoint that carries a barangay — `/admin/residents` first — and
         * `QueryBudgetTest` measures precisely that slope.
         */
        $this->assertSame([], $queries, 'The map queried again after it was loaded.');
    }
}
