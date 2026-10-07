<?php

declare(strict_types=1);

use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use App\Domain\Operations\TaskSubjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests: who asked to change what, who said yes, and whether it was ever carried out.
 *
 * The table is shaped around one distinction the product refuses to lose: **approval is not execution.** This
 * product does not write to the ad platforms yet, so approving a pause pauses nothing — a person goes and
 * does it afterwards. The enactment columns are where that fact lives, and they make possible the figure an
 * agency never has: how much approved work nobody has carried out.
 *
 * Seven rules are CHECK constraints rather than conventions:
 *
 *  - **A request says why.** `rationale` must be non-empty. A request to change what a client spends with no
 *    stated reason is not something anybody can approve.
 *  - **A decline says why, and so does a withdrawal.** The decline reason is what the next proposal is built
 *    on; the withdrawal reason is the difference between a decision and a request that vanished.
 *  - **A decision records when and by whose fingerprint.** `decided_at` and `decided_fingerprint` are both
 *    required for a decided state and refused otherwise, so the record always says that whoever approved this
 *    saw the proposal they approved.
 *  - **Enactment only exists after approval.** Anything other than `not_required` requires `state =
 *    'approved'`, so no row can claim a declined change was carried out.
 *  - **A carried-out change records when.** `enactment = 'confirmed'` requires `enacted_at`.
 *  - **An abandoned change says why.** It is a second decision, and it is the one a client asks about.
 *  - **A subject link is whole or absent**, the same rule tasks follow.
 *
 * `supersedes_id` is how an amended proposal is expressed. A settled request is never re-decided — that would
 * produce a trail in which one request was both declined and approved — so a changed proposal is a new row
 * pointing at the one it replaces, and both decisions stay on the record. It is the same rule an approved
 * budget follows, where the agreed figure is archived rather than revised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /** Nullable: an internal tracking change belongs to no client. */
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('kind');
            $table->string('title');
            /** Why. Required — see the note at the top of this migration. */
            $table->text('rationale');

            /*
             * What is being proposed, as the requester stated it. JSONB because the shape differs per kind and
             * this product deliberately does not model a platform's targeting schema in order to describe a
             * change to it: a half-modelled schema would quietly drop the field that mattered.
             */
            $table->jsonb('proposal');
            /** A hash of the canonical proposal. A decision must name it — see `ProposalFingerprint`. */
            $table->string('proposal_fingerprint', 64);

            $table->string('state')->default(ApprovalState::Pending->value);
            $table->string('enactment')->default(EnactmentState::NotRequired->value);

            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            /** The fingerprint the decider was looking at. Equal to the proposal's, and recorded as evidence. */
            $table->string('decided_fingerprint', 64)->nullable();

            $table->text('withdrawn_reason')->nullable();

            $table->foreignUuid('enacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enacted_at')->nullable();
            $table->text('enactment_note')->nullable();

            /*
             * The request this one replaces, for an amended proposal. The foreign key is added after the table
             * exists rather than inline: a self-reference declared inside `create` is emitted before the
             * primary key it points at, and Postgres refuses it.
             */
            $table->uuid('supersedes_id')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'state']);
            $table->index(['workspace_id', 'enactment']);
            $table->index(['workspace_id', 'client_id', 'state']);
            $table->index(['workspace_id', 'kind']);
            $table->index(['workspace_id', 'subject_type', 'subject_id']);
        });

        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->foreign('supersedes_id')->references('id')->on('approval_requests')->nullOnDelete();
        });

        DB::statement(sprintf(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_kind_check CHECK (kind IN ('%s'))",
            implode("','", ApprovalKind::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_state_check CHECK (state IN ('%s'))",
            implode("','", ApprovalState::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_enactment_check CHECK (enactment IN ('%s'))",
            implode("','", EnactmentState::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_subject_type_check CHECK (\n"
            ."  subject_type IS NULL OR subject_type IN ('%s')\n"
            .')',
            implode("','", TaskSubjectType::values()),
        ));

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_has_rationale CHECK (\n"
            ."  btrim(rationale) <> ''\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_decline_has_reason CHECK (\n"
            ."  state <> 'declined' OR (decision_reason IS NOT NULL AND btrim(decision_reason) <> '')\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_withdrawal_has_reason CHECK (\n"
            ."  state <> 'withdrawn' OR (withdrawn_reason IS NOT NULL AND btrim(withdrawn_reason) <> '')\n"
            .')'
        );

        /*
         * Both directions, and both columns. A decided request with no timestamp is a claim with no date; a
         * decided request with no fingerprint cannot say that whoever approved it read what they approved.
         */
        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_decision_is_evidenced CHECK (\n"
            ."  (state IN ('approved', 'declined'))\n"
            .'  = (decided_at IS NOT NULL AND decided_fingerprint IS NOT NULL)'."\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_enactment_needs_approval CHECK (\n"
            ."  enactment = 'not_required' OR state = 'approved'\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_confirmed_has_time CHECK (\n"
            ."  enactment <> 'confirmed' OR enacted_at IS NOT NULL\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_abandonment_has_note CHECK (\n"
            ."  enactment <> 'abandoned' OR (enactment_note IS NOT NULL AND btrim(enactment_note) <> '')\n"
            .')'
        );

        DB::statement(
            'ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_subject_is_whole CHECK ('."\n"
            .'  (subject_type IS NULL) = (subject_id IS NULL)'."\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_title_present CHECK (btrim(title) <> '')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
