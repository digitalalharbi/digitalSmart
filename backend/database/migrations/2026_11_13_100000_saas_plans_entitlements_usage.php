<?php

declare(strict_types=1);

use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;
use App\Domain\Saas\PlanCatalogue;
use App\Domain\Saas\SeatType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Plans, what they include, what a workspace was given beyond its plan, and what it has used.
 *
 * No billing here: a plan is a set of features and limits, a subscription is which plan a workspace is on. Billing attaches later
 * through an adapter. Every workspace that exists gets the configured default plan, so nothing anybody could do yesterday is
 * refused today by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->boolean('is_fixture')->default(true);
            $table->timestampsTz();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature', 32);
            $table->primary(['plan_id', 'feature']);
        });
        DB::statement(sprintf("ALTER TABLE plan_features ADD CONSTRAINT plan_features_feature_check CHECK (feature IN ('%s'))", implode("','", Feature::values())));

        Schema::create('plan_limits', function (Blueprint $table): void {
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('limit_key', 32);
            /* Null: not bounded by this plan. */
            $table->unsignedBigInteger('value')->nullable();
            $table->primary(['plan_id', 'limit_key']);
        });
        DB::statement(sprintf("ALTER TABLE plan_limits ADD CONSTRAINT plan_limits_key_check CHECK (limit_key IN ('%s'))", implode("','", Limit::values())));

        Schema::create('workspace_subscriptions', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestampTz('assigned_at');
            $table->string('assigned_by', 190)->nullable();
            $table->timestampsTz();
        });

        /*
         * Append-only: every override is a row, and the newest row for a key is the one in force. A value of `inherit` returns
         * the key to the plan. The previous value is recorded beside the new one so the history reads without a join.
         */
        Schema::create('workspace_entitlement_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('key', 32);
            $table->string('value', 32);
            $table->string('previous_value', 32)->nullable();
            $table->text('reason');
            $table->string('changed_by', 190);
            $table->timestampTz('created_at');

            $table->index(['workspace_id', 'kind', 'key', 'created_at']);
        });
        DB::statement("ALTER TABLE workspace_entitlement_overrides ADD CONSTRAINT workspace_entitlement_overrides_kind_check CHECK (kind IN ('feature', 'limit'))");
        DB::statement('ALTER TABLE workspace_entitlement_overrides ADD CONSTRAINT workspace_entitlement_overrides_reason_check CHECK (length(trim(reason)) > 0)');

        Schema::create('usage_meters', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('meter', 32);
            /* The calendar month, UTC: YYYY-MM. */
            $table->char('period', 7);
            $table->unsignedBigInteger('value')->default(0);
            $table->timestampTz('updated_at');
            $table->primary(['workspace_id', 'meter', 'period']);
        });

        /* One row per counted event, so a retried event is counted once. Kept only for the current and previous month. */
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('meter', 32);
            $table->string('event_key', 128);
            $table->timestampTz('occurred_at');
            $table->primary(['workspace_id', 'meter', 'event_key']);
        });

        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->string('seat_type', 8)->default(SeatType::Full->value);
        });
        DB::statement(sprintf("ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_seat_type_check CHECK (seat_type IN ('%s'))", implode("','", SeatType::values())));
        DB::statement("UPDATE workspace_members m SET seat_type = 'client' FROM roles r WHERE r.id = m.role_id AND r.slug = 'client-viewer'");

        $now = now();

        foreach (PlanCatalogue::plans() as $code => $plan) {
            $id = (string) Str::uuid7();
            DB::table('plans')->insert(['id' => $id, 'code' => $code, 'name' => $plan['name'], 'is_fixture' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('plan_features')->insert(array_map(static fn (Feature $f): array => ['plan_id' => $id, 'feature' => $f->value], $plan['features']));
            DB::table('plan_limits')->insert(array_map(
                static fn (string $key, ?int $value): array => ['plan_id' => $id, 'limit_key' => $key, 'value' => $value],
                array_keys($plan['limits']),
                array_values($plan['limits']),
            ));
        }

        $default = DB::table('plans')->where('code', config('digitalsmart.saas.default_plan', PlanCatalogue::AGENCY))->value('id');

        DB::statement(
            'INSERT INTO workspace_subscriptions (workspace_id, plan_id, assigned_at, assigned_by, created_at, updated_at)
             SELECT id, ?, now(), ?, now(), now() FROM workspaces',
            [$default, 'migration'],
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workspace_members DROP CONSTRAINT workspace_members_seat_type_check');
        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->dropColumn('seat_type');
        });

        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('usage_meters');
        Schema::dropIfExists('workspace_entitlement_overrides');
        Schema::dropIfExists('workspace_subscriptions');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
