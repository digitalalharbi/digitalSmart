<?php

declare(strict_types=1);

use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskSubjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational work: the board an agency runs its week from.
 *
 * Five rules live in the schema rather than in the code that writes to it, because each has an obvious
 * wrong version and none is visible once the data is in:
 *
 *  - **Blocked says what is blocking it, and cancelled says why.** Two CHECK constraints. A column of
 *    unexplained blocked cards is a column nobody can clear.
 *  - **Done records when it was done.** `completed_at` is required for the done state and refused for
 *    every other, so "finished" always carries its evidence and reopening work cannot leave a stale
 *    completion time behind.
 *  - **A due date is a date.** `due_on` is a `date`, not a timestamp, because "due Thursday" has no time
 *    in it and storing one invents a deadline the person who typed it never set — which then makes
 *    everything due today overdue by lunchtime.
 *  - **A subject link is whole or absent.** A CHECK ties `subject_type` and `subject_id` together. Half a
 *    link is worse than none: it renders as a link and goes nowhere.
 *  - **Priority and state are closed vocabularies.** CHECKs from the enums, so a typo in a payload cannot
 *    create a sixth column nobody styled.
 *
 * There is deliberately **no foreign key behind the subject**, and `app/Domain/Operations/TaskSubjectType`
 * explains the trade: the read model resolves a subject on every read and reports a missing one as
 * missing, rather than a dead link rendering as a live one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /*
             * Both nullable. An agency has internal work — a hiring task, a process fix — that belongs to
             * no client, and a task about a client often precedes anyone deciding which project pays for it.
             */
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('state')->default(TaskState::Todo->value);
            $table->string('priority')->default(TaskPriority::Normal->value);

            /*
             * Nullable, and counted. Unassigned open work is published as its own figure rather than left
             * out of the workload chart, because a chart of named people only would show a comfortable
             * team beside a pile nobody owns.
             */
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            /** A date, not a timestamp. See the note at the top of this migration. */
            $table->date('due_on')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('blocked_reason')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestamp('state_changed_at')->nullable();

            /** What the work is about. A link, never a claim — see `TaskSubjectType`. */
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'state']);
            $table->index(['workspace_id', 'assignee_id', 'state']);
            $table->index(['workspace_id', 'due_on']);
            $table->index(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'subject_type', 'subject_id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_state_check CHECK (state IN ('%s'))",
            implode("','", TaskState::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('%s'))",
            implode("','", TaskPriority::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_subject_type_check CHECK (\n"
            ."  subject_type IS NULL OR subject_type IN ('%s')\n"
            .')',
            implode("','", TaskSubjectType::values()),
        ));

        DB::statement(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_blocked_has_reason CHECK (\n"
            ."  state <> 'blocked' OR (blocked_reason IS NOT NULL AND btrim(blocked_reason) <> '')\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_cancelled_has_reason CHECK (\n"
            ."  state <> 'cancelled' OR (cancelled_reason IS NOT NULL AND btrim(cancelled_reason) <> '')\n"
            .')'
        );

        /*
         * Both directions. "Done" with no completion time is a claim with no evidence; a completion time
         * on work that is not done is a leftover from a reopening, and the next report to average
         * turnaround would quietly include it.
         */
        DB::statement(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_done_has_completed_at CHECK (\n"
            ."  (state = 'done') = (completed_at IS NOT NULL)\n"
            .')'
        );

        // Whole or absent. A half-written link renders as a link and goes nowhere.
        DB::statement(
            'ALTER TABLE tasks ADD CONSTRAINT tasks_subject_is_whole CHECK ('."\n"
            .'  (subject_type IS NULL) = (subject_id IS NULL)'."\n"
            .')'
        );

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_title_present CHECK (btrim(title) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
