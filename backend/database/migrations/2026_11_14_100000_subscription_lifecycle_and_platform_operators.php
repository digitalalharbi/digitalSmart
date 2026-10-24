<?php

declare(strict_types=1);

use App\Domain\Saas\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A subscription has a state, and the platform has operators.
 *
 * Every existing subscription is `active`: nothing about a workspace changes by being migrated. An operator is a flag on the
 * person, set only from the console, and gives no membership of any workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_subscriptions', function (Blueprint $table): void {
            $table->string('status', 16)->default(SubscriptionStatus::Active->value);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('grace_ends_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->string('status_changed_by', 190)->nullable();
            $table->index('status');
        });

        DB::statement(sprintf(
            "ALTER TABLE workspace_subscriptions ADD CONSTRAINT workspace_subscriptions_status_check CHECK (status IN ('%s'))",
            implode("','", SubscriptionStatus::values()),
        ));
        /* A workspace that is stopped says why; a reason is what the people in it are shown. */
        DB::statement("ALTER TABLE workspace_subscriptions ADD CONSTRAINT workspace_subscriptions_stopped_reason_check CHECK (status NOT IN ('suspended','cancelled') OR status_reason IS NOT NULL)");

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_operator')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_operator');
        });

        DB::statement('ALTER TABLE workspace_subscriptions DROP CONSTRAINT workspace_subscriptions_stopped_reason_check');
        DB::statement('ALTER TABLE workspace_subscriptions DROP CONSTRAINT workspace_subscriptions_status_check');
        Schema::table('workspace_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'trial_ends_at', 'grace_ends_at', 'status_reason', 'status_changed_at', 'status_changed_by']);
        });
    }
};
