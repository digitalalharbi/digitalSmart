<?php

declare(strict_types=1);

use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\CreativeState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creative operations: briefs, versions, approvals, comments, and recorded platform specifications.
 *
 * Three rules are enforced by the schema rather than left to the code that writes to it, because each has
 * an obvious wrong version and none of them is visible once the data is in:
 *
 *  - **An approval belongs to a version.** `creative_approvals.creative_version_id` is the foreign key,
 *    and there is deliberately no approval column on the request. A client approved a file; the next file
 *    is unapproved until somebody says otherwise, and a schema that recorded approval on the request would
 *    make that impossible to express.
 *  - **Sending work back must say why.** A CHECK constraint refuses a `changes_requested` outcome with no
 *    reason. The note is the only record of what the reviewer objected to.
 *  - **A comment's audience is explicit.** `visibility` has no default. An agency's internal note on a
 *    client's creative reaching that client ends a relationship, and a default is how it happens.
 *
 * `creative_specs` carries **provenance rather than confidence**: the page a requirement was read on and
 * the date somebody read it. The platforms do not publish these in a form a program can read, so a
 * specification here is recorded knowledge with an age, and a check against an old one says so. See
 * docs/CREATIVE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            /** Nullable: a brief can exist before anybody decides which project pays for it. */
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('title');
            $table->text('brief')->nullable();
            $table->string('state')->default(CreativeState::Draft->value);
            $table->string('kind');

            /*
             * Where the work is meant to run. Both nullable, because a brief often precedes the decision —
             * and a null placement is why most specification checks honestly report that they checked
             * nothing rather than passing.
             */
            $table->string('provider')->nullable();
            $table->string('placement')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            /** Why it was cancelled, which is the only record of a decision not to do the work. */
            $table->text('cancelled_reason')->nullable();
            $table->timestamp('state_changed_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'state']);
            $table->index(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'due_at']);
        });

        DB::statement(sprintf(
            "ALTER TABLE creative_requests ADD CONSTRAINT creative_requests_state_check CHECK (state IN ('%s'))",
            implode("','", CreativeState::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE creative_requests ADD CONSTRAINT creative_requests_kind_check CHECK (kind IN ('%s'))",
            implode("','", AssetKind::values()),
        ));

        /*
         * Cancelling without a reason is refused, like a disqualified lead. "We decided not to make this"
         * is a decision somebody will be asked about in three months.
         */
        DB::statement(
            "ALTER TABLE creative_requests ADD CONSTRAINT creative_requests_cancelled_has_reason CHECK (\n"
            ."  state <> 'cancelled' OR (cancelled_reason IS NOT NULL AND btrim(cancelled_reason) <> '')\n"
            .')'
        );

        Schema::create('creative_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('creative_request_id')->constrained('creative_requests')->cascadeOnDelete();

            /** One, two, three. Shown to clients, so it counts from one rather than from zero. */
            $table->unsignedInteger('number');
            $table->string('kind');
            $table->string('label')->nullable();
            /** Where the file is. Nullable for copy, which has no file. */
            $table->string('storage_path')->nullable();
            $table->text('body')->nullable();

            /*
             * What this product measured, each nullable because **null means not measured rather than
             * zero**. A video whose duration could not be read has an unknown duration, and a check that
             * treated unknown as zero would report it comfortably inside a minimum.
             */
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->string('format')->nullable();
            $table->unsignedInteger('text_length')->nullable();
            $table->string('unmeasured_reason')->nullable();

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['creative_request_id', 'number'], 'creative_versions_unique');
            $table->index(['workspace_id', 'creative_request_id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE creative_versions ADD CONSTRAINT creative_versions_kind_check CHECK (kind IN ('%s'))",
            implode("','", AssetKind::values()),
        ));

        // Numbering from one, so a version number in a client e-mail means what it says.
        DB::statement('ALTER TABLE creative_versions ADD CONSTRAINT creative_versions_number_check CHECK (number >= 1)');

        Schema::create('creative_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /*
             * The version, not the request. The foreign key *is* the rule: approval attaches to a file, and
             * a schema that pointed at the request could not express a later file being unapproved.
             */
            $table->foreignUuid('creative_version_id')->constrained('creative_versions')->cascadeOnDelete();

            $table->string('outcome');
            $table->text('reason')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['workspace_id', 'creative_version_id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE creative_approvals ADD CONSTRAINT creative_approvals_outcome_check CHECK (outcome IN ('%s'))",
            implode("','", ApprovalOutcome::values()),
        ));

        DB::statement(
            "ALTER TABLE creative_approvals ADD CONSTRAINT creative_approvals_changes_have_reason CHECK (\n"
            ."  outcome <> 'changes_requested' OR (reason IS NOT NULL AND btrim(reason) <> '')\n"
            .')'
        );

        Schema::create('creative_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('creative_request_id')->constrained('creative_requests')->cascadeOnDelete();
            /** Nullable: a comment can be about the brief rather than about a version. */
            $table->foreignUuid('creative_version_id')->nullable()->constrained('creative_versions')->cascadeOnDelete();

            /** No default. See the note at the top of this migration. */
            $table->string('visibility');
            $table->text('body');
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'creative_request_id']);
            $table->index(['workspace_id', 'visibility']);
        });

        DB::statement(sprintf(
            "ALTER TABLE creative_comments ADD CONSTRAINT creative_comments_visibility_check CHECK (visibility IN ('%s'))",
            implode("','", CommentVisibility::values()),
        ));

        DB::statement(
            "ALTER TABLE creative_comments ADD CONSTRAINT creative_comments_body_present CHECK (btrim(body) <> '')"
        );

        /*
         * Recorded platform specifications.
         *
         * Not workspace-scoped: these describe a platform, not a client, so one recording serves every
         * tenant. They are read-only through the API for the same reason — a workspace able to edit them
         * would be editing every other workspace's checks.
         */
        Schema::create('creative_specs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('placement');
            $table->string('kind');

            /** The bounds, keyed by requirement. JSONB because what a platform states varies by placement. */
            $table->jsonb('bounds');

            /** Provenance. Every one of these is required except the note — see the class comment. */
            $table->string('source_url');
            $table->date('documented_at');
            $table->string('recorded_by')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'placement', 'kind'], 'creative_specs_unique');
        });

        DB::statement(sprintf(
            "ALTER TABLE creative_specs ADD CONSTRAINT creative_specs_kind_check CHECK (kind IN ('%s'))",
            implode("','", AssetKind::values()),
        ));

        /*
         * A specification with no source is folklore. Enforced here rather than trusted to the seeder,
         * because the whole value of this table is that every number in it can be traced to a page.
         */
        DB::statement(
            "ALTER TABLE creative_specs ADD CONSTRAINT creative_specs_has_source CHECK (btrim(source_url) <> '')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_specs');
        Schema::dropIfExists('creative_comments');
        Schema::dropIfExists('creative_approvals');
        Schema::dropIfExists('creative_versions');
        Schema::dropIfExists('creative_requests');
    }
};
