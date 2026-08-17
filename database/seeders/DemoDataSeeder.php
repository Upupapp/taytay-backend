<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Application\HouseholdMembershipService;
use Modules\ResidentProfile\Application\HouseholdRegistry;
use Modules\ResidentProfile\Application\ResidentRegistry;
use Modules\Shared\Application\ActorContext;

/**
 * A coherent fictional dataset for demonstration and development (ADR 0040).
 *
 * **IT REFUSES TO RUN IN PRODUCTION, AND THAT IS THE FIRST THING IT DOES.** A demo seeder is a
 * program whose entire purpose is to insert invented people into a resident registry. The one
 * environment where that is catastrophic is the one where it would be hardest to unpick: a
 * caseworker cannot tell an invented resident from a real one by looking, and an invented one who
 * gets assistance approved is a real disbursement to nobody.
 *
 * ── EVERY NAME, NUMBER AND ADDRESS HERE IS INVENTED ──────────────────────────────────
 *
 * The master command says never to use real citizen names, phones, emails or IDs, and the
 * discipline is stronger than picking unfamiliar names:
 *
 *  * **emails** are `@example.test` — a reserved TLD (RFC 6761) that can never resolve, so a
 *    misdirected notification cannot reach a stranger;
 *  * **mobile numbers** are `+639170000xxx`, a fixed block reserved by this seeder rather than
 *    drawn at random, so no run can accidentally generate a number somebody actually holds;
 *  * **PhilSys numbers appear nowhere at all.** Not invented ones either — a plausible-looking
 *    government identifier in a database is a plausible-looking government identifier, and
 *    somebody will eventually paste one into a form that checks it.
 *
 * `DemoDataIsFictionalTest` asserts all three against what actually landed in the tables.
 *
 * ── COHERENCE IS BUILT, NOT ASSERTED ─────────────────────────────────────────────────
 *
 * Records are created **through the application services**, in dependency order, so the
 * relationships hold by construction: a household exists before its members, a member is a
 * resident who exists, and the barangay on the household is the barangay on the resident. A
 * seeder that wrote rows directly would produce data that looks right and violates an invariant
 * the API enforces — which is worse than no demo data, because it teaches a developer that an
 * impossible state is possible.
 */
final class DemoDataSeeder extends Seeder
{
    /*
     * `WithoutModelEvents` IS DELIBERATELY NOT USED, and the reason is a trap worth naming.
     *
     * It is standard seeder hygiene — it stops observers firing during a bulk insert — and here it
     * silently breaks a model invariant: every model in this system mints its public UUID in a
     * `creating` hook, and suppressing events means the column arrives null. The first run of this
     * seeder failed on a NOT NULL constraint for exactly that reason.
     *
     * A seeder that goes through the application services needs the services to behave the way
     * they behave in production, events included. That is the whole point of going through them.
     */

    /**
     * The reserved mobile block. Fixed, not random — see the class docblock.
     */
    private const MOBILE_PREFIX = '+63917000';

    /**
     * Invented households, one per barangay, with invented members.
     *
     * Surnames are common Philippine ones and the combinations are not drawn from anywhere; the
     * point of the fixture is that it is *plausible* enough to demonstrate the system and
     * *checkably* fictional.
     *
     * @var list<array{barangay: string, household: string, street: string, members: list<array{first: string, last: string, sex: string, born: string, civil: string, relationship: string}>}>
     */
    private const HOUSEHOLDS = [
        [
            'barangay' => 'brgy-dolores',
            'household' => 'Dela Cruz household',
            'street' => '12 Sampaguita Street',
            'members' => [
                ['first' => 'Rosario', 'last' => 'Dela Cruz', 'sex' => 'female', 'born' => '1968-04-11', 'civil' => 'widowed', 'relationship' => 'head'],
                ['first' => 'Melchor', 'last' => 'Dela Cruz', 'sex' => 'male', 'born' => '1994-09-02', 'civil' => 'single', 'relationship' => 'child'],
                ['first' => 'Divina', 'last' => 'Dela Cruz', 'sex' => 'female', 'born' => '2011-01-23', 'civil' => 'single', 'relationship' => 'grandchild'],
            ],
        ],
        [
            'barangay' => 'brgy-muzon',
            'household' => 'Bautista household',
            'street' => '7 Ilang-Ilang Street',
            'members' => [
                ['first' => 'Teodoro', 'last' => 'Bautista', 'sex' => 'male', 'born' => '1979-12-30', 'civil' => 'married', 'relationship' => 'head'],
                ['first' => 'Aurelia', 'last' => 'Bautista', 'sex' => 'female', 'born' => '1982-06-17', 'civil' => 'married', 'relationship' => 'spouse'],
                ['first' => 'Nicanor', 'last' => 'Bautista', 'sex' => 'male', 'born' => '2009-03-08', 'civil' => 'single', 'relationship' => 'child'],
            ],
        ],
        [
            'barangay' => 'brgy-san-juan',
            'household' => 'Villanueva household',
            'street' => '145 Rizal Extension',
            'members' => [
                ['first' => 'Consolacion', 'last' => 'Villanueva', 'sex' => 'female', 'born' => '1955-08-05', 'civil' => 'widowed', 'relationship' => 'head'],
                ['first' => 'Emiliano', 'last' => 'Villanueva', 'sex' => 'male', 'born' => '1988-11-14', 'civil' => 'separated', 'relationship' => 'child'],
            ],
        ],
        [
            'barangay' => 'brgy-santa-ana',
            'household' => 'Panganiban household',
            'street' => '3 Kalayaan Road',
            'members' => [
                ['first' => 'Feliciano', 'last' => 'Panganiban', 'sex' => 'male', 'born' => '1991-02-19', 'civil' => 'cohabiting', 'relationship' => 'head'],
                ['first' => 'Marilou', 'last' => 'Panganiban', 'sex' => 'female', 'born' => '1993-07-26', 'civil' => 'cohabiting', 'relationship' => 'partner'],
            ],
        ],
        [
            'barangay' => 'brgy-san-isidro',
            'household' => 'Salazar household',
            'street' => '88 Bagong Silang Street',
            'members' => [
                ['first' => 'Perlita', 'last' => 'Salazar', 'sex' => 'female', 'born' => '1974-05-03', 'civil' => 'married', 'relationship' => 'head'],
                ['first' => 'Rogelio', 'last' => 'Salazar', 'sex' => 'male', 'born' => '1971-10-21', 'civil' => 'married', 'relationship' => 'spouse'],
                ['first' => 'Jomarie', 'last' => 'Salazar', 'sex' => 'male', 'born' => '2004-12-09', 'civil' => 'single', 'relationship' => 'child'],
            ],
        ],
    ];

    public function run(): void
    {
        /*
         * THE GUARD, FIRST AND UNCONDITIONAL.
         *
         * Not `if (! production)` — an allow-list, so an environment name nobody anticipated
         * (`staging-2`, `uat`, an empty `APP_ENV`) is refused rather than treated as safe. Deny by
         * default is the same rule this system applies to authorization, and for the same reason:
         * the failure of a deny-list is silent and total.
         */
        if (! app()->environment(['local', 'testing', 'demo'])) {
            $this->command?->warn('DemoDataSeeder refused to run outside local, testing or demo.');

            return;
        }

        /*
         * Reference data first: a resident cannot exist before their barangay does.
         *
         * `call()`, NOT `callOnce()` — and that distinction was a real bug. `callOnce` remembers
         * across invocations within one process, while `RefreshDatabase` wipes the table between
         * tests: the second test in a class got a seeder that "already ran" and an empty
         * `barangays` table, so every household was skipped and the failure read as a broken
         * seeder rather than as a stale memo.
         *
         * `BarangaySeeder` is idempotent by construction, so calling it every time costs five
         * upserts and removes the trap.
         */
        $this->call(BarangaySeeder::class);

        $actor = ActorContext::system();
        $residents = app(ResidentRegistry::class);
        $households = app(HouseholdRegistry::class);
        $memberships = app(HouseholdMembershipService::class);

        $barangays = DB::table('barangays')->pluck('id', 'code');

        foreach (self::HOUSEHOLDS as $index => $definition) {
            $barangayId = (int) ($barangays[$definition['barangay']] ?? 0);

            if ($barangayId === 0) {
                continue;
            }

            /*
             * THROUGH THE SERVICE, so every invariant the API enforces holds here too. A seeder
             * that wrote rows directly would produce data that looks right and violates a rule the
             * application would have refused — teaching a developer that an impossible state is
             * possible.
             */
            $household = $households->create([
                // A household is identified by a CODE, not a name — the name in the fixture is a
                // label for whoever is reading the seeder.
                'code' => 'DEMO-HH-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'barangay_id' => $barangayId,
                'street_address' => $definition['street'],
            ], $actor);

            foreach ($definition['members'] as $position => $member) {
                $resident = $residents->create([
                    'first_name' => $member['first'],
                    'last_name' => $member['last'],
                    'sex' => $member['sex'],
                    'birth_date' => $member['born'],
                    'civil_status' => $member['civil'],
                    // The SAME barangay as the household. A member living somewhere else is a real
                    // situation and a confusing demo, so the fixture keeps them aligned.
                    'barangay_id' => $barangayId,
                    'street_address' => $definition['street'],
                    'mobile_number' => $this->mobile($index, $position),
                    'email' => $this->email($member['first'], $member['last']),
                ], $actor);

                /*
                 * Membership is effective-dated (ADR 0014). Backdated six months so the demo has
                 * history rather than a registry that all began this morning.
                 */
                $memberships->addMember($household, $resident, $actor, now()->subMonths(6)->toDateString());
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d fictional households across %d barangays.',
            count(self::HOUSEHOLDS),
            $barangays->count(),
        ));
    }

    /**
     * A number from the reserved block.
     *
     * Deterministic rather than random: a random generator can produce a number somebody actually
     * holds, and the fact that it is unlikely is not the same as it being impossible. This block
     * is fixed, documented and asserted by `DemoDataIsFictionalTest`.
     */
    private function mobile(int $household, int $member): string
    {
        return self::MOBILE_PREFIX.str_pad((string) ($household * 10 + $member), 3, '0', STR_PAD_LEFT);
    }

    /**
     * An address in `.test`, which is reserved and can never resolve (RFC 6761).
     *
     * A misdirected notification therefore cannot reach a stranger — which a plausible-looking
     * `@gmail.com` address in a demo dataset absolutely can, the first time somebody points a
     * staging environment at a real mail provider.
     */
    private function email(string $first, string $last): string
    {
        return strtolower($first.'.'.$last).'@example.test';
    }
}
