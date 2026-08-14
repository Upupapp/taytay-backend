<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role assignments — AccessControl's canonical store (ADR 0008, gap G-09).
 *
 * Replaces the provisional `config/access_control.php` map. The module and its
 * RoleAssignmentRepository interface already exist (TAB 01); this is the table that
 * interface was always going to be backed by, so no call site changes when it is wired up.
 *
 * Personal-data classification: `internal`. It records authority, not attributes of a
 * person — but it is privilege-granting, so every write is audited.
 *
 * WHY THERE IS NO FOREIGN KEY ON `subject_id`:
 * the subject is an account owned by the Identity module (built in TAB 05). A cross-module
 * foreign key would couple the two schemas permanently and make the boundary in ADR 0001
 * unenforceable, so CLAUDE.md Article 2.2 requires reference by identifier only. The
 * absence of the constraint is deliberate; referential correctness is AccessControl's
 * application service asking Identity, not the database silently joining.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Identity's account UUID. No FK — see the class docblock.
            $table->uuid('subject_id');

            // Open vocabulary (ADR 0008 §5): roles will grow, and widening a check
            // constraint is a table rewrite. Modules\AccessControl\Domain\Role is the
            // source of truth and is asserted by test.
            $table->string('role', 64);

            // Closed set, fixed by the data-scope design, so it earns a check constraint.
            $table->enum('scope_type', ['all-barangays', 'own-barangay', 'assigned-cases'])
                ->default('all-barangays');

            // Set only when scope_type is own-barangay. Same-module FK, so constrained.
            // restrict: a barangay with staff assigned to it must not vanish underneath
            // them (ADR 0008 §4).
            $table->foreignId('barangay_id')->nullable()->constrained('barangays')->restrictOnDelete();

            // Effective dating (ADR 0008 §11): authority is granted for a period, and
            // "was this person allowed to do that in March" must remain answerable.
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_until')->nullable();

            // Who granted it — an Identity account UUID, again without a FK.
            $table->uuid('granted_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            /*
             * The duplicate-relationship guard (ADR 0008 §5).
             *
             * Without this, "assign role" run twice — a double-clicked button, a retried
             * request — creates two rows, and every count, revocation and audit answer is
             * then wrong.
             *
             * The key deliberately excludes the nullable `barangay_id`: PostgreSQL treats
             * NULLs as distinct, so including it would permit unlimited duplicates for
             * municipality-wide assignments — exactly the rows that matter most. Scope is
             * an attribute of the assignment, not part of its identity.
             */
            $table->unique(['subject_id', 'role'], 'uniq_role_assignments_subject_role');

            $table->index(['subject_id', 'valid_until'], 'idx_role_assignments_subject_validity');
            $table->index('role', 'idx_role_assignments_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
