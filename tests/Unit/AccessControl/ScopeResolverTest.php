<?php

declare(strict_types=1);

namespace Tests\Unit\AccessControl;

use Modules\AccessControl\Application\ScopeResolver;
use Modules\AccessControl\Domain\RoleAssignmentRepository;
use Modules\Shared\Application\DataScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scope resolution, isolated from HTTP and the database.
 *
 * The feature suite proves scopes are *enforced*; this proves they are *derived* correctly,
 * including for combinations the seeded roles never produce. Resolution is where a subtle
 * mistake is most dangerous: it fails open silently, and every downstream check then agrees
 * with it.
 */
final class ScopeResolverTest extends TestCase
{
    #[Test]
    public function no_assignment_resolves_to_none(): void
    {
        // Deny by default. The tempting bug is to treat "no scope recorded" as
        // "unrestricted", which hands the whole municipality to every new account.
        $scope = $this->resolve([]);

        $this->assertTrue($scope->isNone());
        $this->assertFalse($scope->isUnrestricted());
        $this->assertFalse($scope->coversBarangay(1));
        $this->assertFalse($scope->coversBarangay(null));
    }

    #[Test]
    public function the_widest_live_assignment_wins(): void
    {
        // Somebody who is both a barangay clerk and a municipal auditor is a municipal
        // auditor. Narrowing them would make adding a role *remove* access, and the
        // apparent fix — "give them another role" — ratchets permissions upward.
        $scope = $this->resolve([
            ['role' => 'lgu_staff', 'scope_type' => DataScope::OWN_BARANGAY, 'barangay_id' => 3],
            ['role' => 'lgu_admin', 'scope_type' => DataScope::ALL_BARANGAYS, 'barangay_id' => null],
        ]);

        $this->assertTrue($scope->isUnrestricted());
        $this->assertTrue($scope->coversBarangay(99));
    }

    #[Test]
    public function own_barangay_covers_the_assigned_barangays_and_nothing_else(): void
    {
        $scope = $this->resolve([
            ['role' => 'lgu_staff', 'scope_type' => DataScope::OWN_BARANGAY, 'barangay_id' => 3],
        ]);

        $this->assertTrue($scope->coversBarangay(3));
        $this->assertFalse($scope->coversBarangay(4));

        // A record with no barangay at all is reachable only by an unrestricted actor:
        // "unknown barangay" must not become a gap that any scope falls through.
        $this->assertFalse($scope->coversBarangay(null));
    }

    #[Test]
    public function explicit_grants_widen_a_barangay_scope(): void
    {
        $scope = $this->resolve(
            [['role' => 'lgu_staff', 'scope_type' => DataScope::OWN_BARANGAY, 'barangay_id' => 3]],
            granted: [7],
        );

        $this->assertTrue($scope->coversBarangay(3));
        $this->assertTrue($scope->coversBarangay(7));
        $this->assertFalse($scope->coversBarangay(8));
    }

    #[Test]
    public function assigned_cases_is_narrower_than_own_barangay(): void
    {
        $scope = $this->resolve([
            ['role' => 'lgu_staff', 'scope_type' => DataScope::ASSIGNED_CASES, 'barangay_id' => 3],
        ]);

        // The barangay bound still applies, and ownership of the record applies on top.
        $this->assertTrue($scope->coversBarangay(3));
        $this->assertFalse($scope->coversBarangay(4));
        $this->assertTrue($scope->requiresCaseAssignment());
    }

    #[Test]
    public function own_barangay_beats_assigned_cases_when_both_are_held(): void
    {
        $scope = $this->resolve([
            ['role' => 'lgu_staff', 'scope_type' => DataScope::ASSIGNED_CASES, 'barangay_id' => 3],
            ['role' => 'lgu_admin', 'scope_type' => DataScope::OWN_BARANGAY, 'barangay_id' => 4],
        ]);

        $this->assertFalse($scope->requiresCaseAssignment());
        $this->assertTrue($scope->coversBarangay(3));
        $this->assertTrue($scope->coversBarangay(4));
    }

    #[Test]
    public function an_unrecognised_scope_type_fails_closed(): void
    {
        // A half-finished migration or a hand-edited row. The schema refuses such a value
        // today (AuthorizationMatrixTest); this is the layer that has to hold if it ever
        // stops doing so — "district-wide" must read as nothing, never as everything.
        $scope = $this->resolve([
            ['role' => 'lgu_admin', 'scope_type' => 'district-wide', 'barangay_id' => 3],
        ]);

        $this->assertTrue($scope->isNone());
        $this->assertFalse($scope->coversBarangay(3));
    }

    /**
     * @param  list<array{role: string, scope_type: string, barangay_id: int|null}>  $assignments
     * @param  list<int>  $granted
     */
    private function resolve(array $assignments, array $granted = []): DataScope
    {
        $repository = new class($assignments, $granted) implements RoleAssignmentRepository
        {
            public function __construct(private array $assignments, private array $granted) {}

            public function rolesFor(string $subjectId): array
            {
                return array_values(array_unique(array_column($this->assignments, 'role')));
            }

            public function assignmentsFor(string $subjectId): array
            {
                return $this->assignments;
            }

            public function grantedBarangayIdsFor(string $subjectId): array
            {
                return $this->granted;
            }
        };

        return (new ScopeResolver($repository))->forSubject('subject-1');
    }
}
