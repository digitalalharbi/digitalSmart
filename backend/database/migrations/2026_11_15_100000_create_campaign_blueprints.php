<?php

declare(strict_types=1);

use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Blueprint\LaunchObjective;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A campaign described once, before any platform's vocabulary, and kept.
 *
 * It references what already exists rather than copying it: the ad accounts bound to the project, a tracking link (and its UTMs),
 * a conversion definition, and Creative Hub requests. It never holds a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('objective', 24);
            $table->string('budget_type', 8)->default('daily');
            $table->bigInteger('budget_minor');
            $table->char('currency', 3);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->string('landing_url', 2048)->nullable();
            $table->foreignUuid('conversion_event_id')->nullable()->constrained('event_definitions')->nullOnDelete();
            /* {countries[], age_min, age_max, gender, languages[], interests} — intent, matched to each platform by its translator. */
            $table->jsonb('targeting')->default('{}');
            /* {mode: automatic|manual, placements[]} */
            $table->jsonb('placement')->default('{}');
            /* {headline, primary_text, call_to_action} */
            $table->jsonb('ad_copy')->default('{}');
            $table->string('status', 24)->default(BlueprintStatus::Draft->value);
            /* Bumped on every edit, so an approval names the version it read. */
            $table->unsignedInteger('version')->default(1);
            $table->text('status_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'project_id']);
        });

        DB::statement(sprintf("ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_status_check CHECK (status IN ('%s'))", implode("','", BlueprintStatus::values())));
        DB::statement(sprintf("ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_objective_check CHECK (objective IN ('%s'))", implode("','", LaunchObjective::values())));
        DB::statement("ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_budget_check CHECK (budget_minor > 0 AND budget_type IN ('daily','lifetime'))");
        DB::statement('ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_dates_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
        /* A lifetime budget needs an end, or it is not a lifetime. */
        DB::statement("ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_lifetime_check CHECK (budget_type <> 'lifetime' OR ends_at IS NOT NULL)");
        DB::statement("ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_cancel_reason_check CHECK (status <> 'cancelled' OR status_reason IS NOT NULL)");
        /* Approved means somebody approved: the person and the time travel together, from approval onwards. */
        DB::statement('ALTER TABLE campaign_blueprints ADD CONSTRAINT campaign_blueprints_approved_check CHECK ((approved_at IS NULL) = (approved_by IS NULL))');

        Schema::create('campaign_blueprint_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('blueprint_id')->constrained('campaign_blueprints')->cascadeOnDelete();
            $table->foreignUuid('external_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->timestampsTz();
            $table->unique(['blueprint_id', 'external_account_id']);
        });

        Schema::create('campaign_blueprint_creatives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('blueprint_id')->constrained('campaign_blueprints')->cascadeOnDelete();
            $table->foreignUuid('creative_request_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['blueprint_id', 'creative_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_blueprint_creatives');
        Schema::dropIfExists('campaign_blueprint_targets');
        Schema::dropIfExists('campaign_blueprints');
    }
};
