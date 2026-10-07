<?php

declare(strict_types=1);

use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports: a saved definition, the snapshots taken from it, and the links it was shared on.
 *
 * Five rules live in the schema, because a report is the most dangerous artefact this product makes — it gets
 * e-mailed, quoted in a meeting, and read months later by somebody who was not in the conversation.
 *
 *  - **The period is a rule, not two dates.** `period_window` holds "last 30 days"; `custom_from`/`custom_until` are
 *    required only for a custom window, and a CHECK enforces both-or-neither. A saved report with frozen dates is
 *    one somebody has to remember to edit, and the one they forget is the one that gets sent.
 *  - **A snapshot stores output, not a query.** `report_snapshots.payload` is the rendered sections with their
 *    figures and their source states. It is never recomputed, which is the whole reason it exists: a client who
 *    was sent a figure must either see that figure or be told plainly they are looking at today's.
 *  - **A share token is stored hashed.** `report_shares.token_hash`, never the token. A database dump must not
 *    hand out working links to clients' performance data, and a share link is a credential.
 *  - **Every share expires.** `expires_at` is NOT NULL. A link with no end outlives the engagement, the account
 *    manager and sometimes the relationship.
 *  - **A shared link is client-safe by construction.** `report_shares` has no audience column, because there is
 *    only one answer: internal commentary is excluded from the shared read in SQL. A column would invite a
 *    future caller to set it to `internal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /** Nullable: an agency-wide report across every client is a legitimate thing to save. */
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            /*
             * The period rule. See the note at the top of this migration.
             *
             * `period_window` rather than `window`, which is a reserved word in Postgres — and the longer name is
             * the better one anyway: "window" alone could mean a date range or an attribution lookback.
             */
            $table->string('period_window')->default(ReportWindow::Last30Days->value);
            $table->date('custom_from')->nullable();
            $table->date('custom_until')->nullable();

            $table->string('comparison')->default(ComparisonMode::None->value);
            $table->string('audience')->default(ReportAudience::Internal->value);

            /*
             * The filters, as the analytics layer already understands them. JSONB rather than six join tables
             * because this is a saved *question*, not a relationship — and the shape is `AnalyticsFilters`, which
             * already exists and is already tested.
             */
            $table->jsonb('filters')->default('{}');

            /** The agency's own commentary. Never leaves the building — see `ReportAudience`. */
            $table->text('internal_note')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'name']);
        });

        DB::statement(sprintf(
            "ALTER TABLE reports ADD CONSTRAINT reports_window_check CHECK (period_window IN ('%s'))",
            implode("','", ReportWindow::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE reports ADD CONSTRAINT reports_comparison_check CHECK (comparison IN ('%s'))",
            implode("','", ComparisonMode::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE reports ADD CONSTRAINT reports_audience_check CHECK (audience IN ('%s'))",
            implode("','", ReportAudience::values()),
        ));

        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_name_present CHECK (btrim(name) <> '')");

        /*
         * Both dates or neither, and only for a custom window. A custom report missing a date would otherwise
         * resolve to something plausible and wrong.
         */
        DB::statement(
            "ALTER TABLE reports ADD CONSTRAINT reports_custom_has_dates CHECK (\n"
            ."  (period_window = 'custom') = (custom_from IS NOT NULL AND custom_until IS NOT NULL)\n"
            .')'
        );

        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT reports_custom_dates_ordered CHECK ('."\n"
            .'  custom_from IS NULL OR custom_until IS NULL OR custom_from <= custom_until'."\n"
            .')'
        );

        Schema::create('report_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('kind');
            $table->string('title')->nullable();

            /** The metrics, in the order they are shown. A list, because the order is part of the definition. */
            $table->jsonb('metrics');
            /** Required for a ranking or a table; null otherwise. A CHECK cannot see the kind's rule, so the
             * use case enforces it and this column stays nullable. */
            $table->string('dimension')->nullable();
            $table->unsignedInteger('row_limit')->nullable();

            $table->timestamps();

            $table->unique(['report_id', 'position'], 'report_sections_unique_position');
            $table->index(['workspace_id', 'report_id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_kind_check CHECK (kind IN ('%s'))",
            implode("','", SectionKind::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE report_sections ADD CONSTRAINT report_sections_dimension_check CHECK (\n"
            ."  dimension IS NULL OR dimension IN ('%s')\n"
            .')',
            implode("','", Dimension::values()),
        ));

        /*
         * Metrics are a non-empty JSON array of known metric values. Checked in the database as well as in the use
         * case, because a section with no metrics renders as an empty box and reads as a broken product.
         */
        DB::statement(
            'ALTER TABLE report_sections ADD CONSTRAINT report_sections_has_metrics CHECK ('."\n"
            ."  jsonb_array_length(metrics) > 0\n"
            .')'
        );

        DB::statement(sprintf(
            'ALTER TABLE report_sections ADD CONSTRAINT report_sections_metrics_known CHECK ('."\n"
            ."  metrics <@ '%s'::jsonb\n"
            .')',
            json_encode(Metric::values(), JSON_THROW_ON_ERROR),
        ));

        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();

            /** The resolved dates, so a snapshot says which period it is about without resolving a rule again. */
            $table->date('period_from');
            $table->date('period_until');

            /** The rendered output. Never recomputed — see the note at the top of this migration. */
            $table->jsonb('payload');
            /** The weakest source state anywhere in the payload, lifted out so a list can be labelled. */
            $table->string('source');
            $table->string('label')->nullable();

            $table->foreignUuid('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['workspace_id', 'report_id', 'taken_at']);
        });

        DB::statement(
            'ALTER TABLE report_snapshots ADD CONSTRAINT report_snapshots_period_ordered CHECK ('."\n"
            .'  period_from <= period_until'."\n"
            .')'
        );

        Schema::create('report_shares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();
            /*
             * A share points at a snapshot, or at nothing. Pointing at a snapshot is a frozen link; pointing at
             * nothing recomputes on every open. Both are legitimate and the visitor is told which they have.
             */
            $table->foreignUuid('snapshot_id')->nullable()->constrained('report_snapshots')->cascadeOnDelete();

            /** Hashed, never the token. A share link is a credential. */
            $table->string('token_hash', 64)->unique();
            /** Enough of the token to recognise a link in a list, without being enough to use one. */
            $table->string('token_hint', 12);

            /** NOT NULL. Every share expires — see the note at the top of this migration. */
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();

            /** Who it was for, as free text. A name in an e-mail, not a user account. */
            $table->string('recipient_label')->nullable();

            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'report_id']);
        });

        DB::statement(
            "ALTER TABLE report_shares ADD CONSTRAINT report_shares_revocation_has_reason CHECK (\n"
            ."  revoked_at IS NULL OR (revoked_reason IS NOT NULL AND btrim(revoked_reason) <> '')\n"
            .')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
        Schema::dropIfExists('report_snapshots');
        Schema::dropIfExists('report_sections');
        Schema::dropIfExists('reports');
    }
};
