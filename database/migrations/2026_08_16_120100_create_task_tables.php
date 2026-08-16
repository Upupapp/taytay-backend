<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks and work queues (ADR 0024).
 *
 * A TASK IS THE ANSWER TO "WHAT HAPPENS NEXT", AS A RECORD RATHER THAN AN INFERENCE. A screen
 * that derives the next step from a case's status can only say what the *process* expects; a task
 * says what this office actually undertook to do, by when, and who owes it.
 *
 * THE LINKED ENTITY IS AN IDENTIFIER AND A TYPE, NOTHING MORE. No title copied from a case, no
 * beneficiary name, no narrative. A task row is read by every queue in the building — "my tasks",
 * "team queue", "due today" — and anything denormalised onto it is disclosed to everyone who can
 * see the queue, regardless of whether they may open the thing it points at (ADR 0024 §2).
 *
 * That is the acceptance criterion "linked entity access is still policy checked", made
 * structural: there is nothing on a task worth reading if you cannot open its subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('type', 48);

            /*
             * ── the linked entity ─────────────────────────────────────────────────────────
             *
             * A type and a UUID. No foreign key: a task may point at a case, a referral, a visit,
             * a release or a duplicate pair, and several of those live in other modules
             * (Article 2.2).
             *
             * Nullable, because a task genuinely may not have one — "call the barangay about the
             * distribution venue" is real work with no record behind it.
             */
            $table->string('subject_type', 48)->nullable();
            $table->uuid('subject_id')->nullable();

            /*
             * A SHORT INSTRUCTION, not a description of the subject.
             *
             * "Return with the barangay certificate request form" is safe in a queue. "Follow up
             * with Maria Santos about her VAWC referral" is not — and the difference is the
             * whole reason this column is written by the code that raises the task rather than
             * copied from whatever the task points at.
             */
            $table->string('title', 160);

            // Identity account UUID. Null means unassigned — a real and important state, not an
            // error: an unassigned task in a team queue is work nobody has picked up yet.
            $table->uuid('assigned_to')->nullable();

            /*
             * The team that owes it, where the work belongs to a desk rather than a person.
             *
             * A label rather than a foreign key to a team table, because Taytay's MSWDO does not
             * have a formal team structure in this system and inventing one to hold a string
             * would be a table nobody maintains.
             */
            $table->string('team', 48)->nullable();

            $table->string('priority', 16)->default('normal');
            $table->date('due_on')->nullable();

            $table->string('status', 16)->default('open');

            // What happened, in the words of whoever closed it. Null while open.
            $table->string('outcome', 500)->nullable();

            $table->uuid('created_by')->nullable();

            /*
             * Set when a listener raised this task rather than a person.
             *
             * Recorded so a worker can tell "the system noticed this" from "a colleague asked me
             * to do this" — they carry different weight, and a queue that hides the difference
             * trains people to ignore the automatic ones.
             */
            $table->string('raised_by_event', 64)->nullable();

            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            /*
             * ── the indexes the acceptance criterion asks for ─────────────────────────────
             *
             * "Overdue tasks are queryable efficiently." Overdue is `status = open AND due_on <
             * today`, and it is asked three ways: for me, for a team, and for everybody. Each
             * gets a composite index leading with the column that narrows hardest.
             */
            $table->index(['assigned_to', 'status', 'due_on'], 'idx_tasks_mine');
            $table->index(['team', 'status', 'due_on'], 'idx_tasks_team');
            $table->index(['status', 'due_on'], 'idx_tasks_overdue');
            $table->index(['subject_type', 'subject_id'], 'idx_tasks_subject');

            /*
             * ONE OPEN AUTOMATIC TASK PER SUBJECT PER TYPE.
             *
             * The derived column is null unless the task is both open AND raised by an event, so
             * the unique index constrains exactly those — PostgreSQL and SQLite both treat NULLs
             * in a unique index as distinct, the same portable trick ADR 0019 §5 uses.
             *
             * Without it a nightly sweep raises a fresh "referral overdue" task every morning,
             * and within a fortnight the queue is fourteen copies of one piece of work and
             * nobody trusts it (ADR 0024 §3).
             */
            $table->string('automation_key', 128)->nullable();
            $table->unique('automation_key', 'uniq_tasks_automation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
