<?php

declare(strict_types=1);

use App\Domain\Operations\TaskSubjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules one level and two levels below a campaign.
 *
 * A rule already states its level (`object_level`, campaign by default); this lets it name the ad groups and ads it
 * is scoped to, keys its streaks and its history by the entity it judged rather than by the campaign above it, and
 * lets a task or an approval be about an ad group or an ad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->jsonb('ad_group_ids')->default('[]');
            $table->jsonb('ad_ids')->default('[]');
        });

        /*
         * A streak is about one entity. Every existing one is about a campaign, so its entity is that campaign; the
         * campaign column stays, as the parent every entity has.
         */
        Schema::table('automation_rule_streaks', function (Blueprint $table): void {
            $table->uuid('entity_id')->nullable();
        });
        DB::statement('UPDATE automation_rule_streaks SET entity_id = campaign_id');
        DB::statement('ALTER TABLE automation_rule_streaks ALTER COLUMN entity_id SET NOT NULL');
        DB::statement('ALTER TABLE automation_rule_streaks DROP CONSTRAINT automation_rule_streaks_pkey');
        DB::statement('ALTER TABLE automation_rule_streaks ADD PRIMARY KEY (rule_id, entity_id)');

        Schema::table('automation_rule_executions', function (Blueprint $table): void {
            $table->uuid('entity_id')->nullable();
            /* The campaign above an ad group, and "campaign › ad group" above an ad — so a row reads without its id. */
            $table->string('parent_label')->nullable();
            $table->index(['rule_id', 'entity_id', 'created_at']);
        });
        DB::statement('UPDATE automation_rule_executions SET entity_id = campaign_id WHERE entity_id IS NULL');

        foreach (['tasks', 'approval_requests'] as $table) {
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s_subject_type_check', $table, $table));
            DB::statement(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s_subject_type_check CHECK (\n  subject_type IS NULL OR subject_type IN ('%s')\n)",
                $table,
                $table,
                implode("','", TaskSubjectType::values()),
            ));
        }
    }

    public function down(): void
    {
        $narrow = array_values(array_diff(TaskSubjectType::values(), ['ad_group', 'ad']));

        foreach (['tasks', 'approval_requests'] as $table) {
            DB::table($table)->whereIn('subject_type', ['ad_group', 'ad'])->update(['subject_type' => null, 'subject_id' => null]);
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s_subject_type_check', $table, $table));
            DB::statement(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s_subject_type_check CHECK (\n  subject_type IS NULL OR subject_type IN ('%s')\n)",
                $table,
                $table,
                implode("','", $narrow),
            ));
        }

        Schema::table('automation_rule_executions', function (Blueprint $table): void {
            $table->dropIndex(['rule_id', 'entity_id', 'created_at']);
            $table->dropColumn(['entity_id', 'parent_label']);
        });

        DB::statement('DELETE FROM automation_rule_streaks WHERE entity_id <> campaign_id');
        DB::statement('ALTER TABLE automation_rule_streaks DROP CONSTRAINT automation_rule_streaks_pkey');
        DB::statement('ALTER TABLE automation_rule_streaks ADD PRIMARY KEY (rule_id, campaign_id)');
        Schema::table('automation_rule_streaks', function (Blueprint $table): void {
            $table->dropColumn('entity_id');
        });

        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn(['ad_group_ids', 'ad_ids']);
        });
    }
};
