<?php

declare(strict_types=1);

use App\Domain\Leads\Automation\AssignmentMode;
use App\Domain\Leads\Automation\AssignmentReason;
use App\Domain\Notifications\NotificationKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happens to a lead the moment a tracked form makes one, and the response time a project promises.
 *
 * Settings are per project. The round-robin pointer is its own row so the choice of the next person can be made under a
 * lock: two leads arriving together must not both be handed to the same person because both read the same pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_automation_settings', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('assignment_mode', 16)->default(AssignmentMode::None->value);
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('create_task')->default(false);
            $table->string('task_title_ar', 160)->nullable();
            $table->string('task_title_en', 160)->nullable();
            $table->string('task_priority', 8)->default('high');
            $table->boolean('notify_owner')->default(true);
            /* The promise: minutes to a first attempt. Null is "no promise made", which is not the same as zero. */
            $table->unsignedSmallInteger('sla_minutes')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement(sprintf(
            "ALTER TABLE lead_automation_settings ADD CONSTRAINT lead_automation_mode_check CHECK (assignment_mode IN ('%s'))",
            implode("','", AssignmentMode::values()),
        ));
        DB::statement(
            'ALTER TABLE lead_automation_settings ADD CONSTRAINT lead_automation_owner_check CHECK ('
            ."assignment_mode NOT IN ('specific','project_default') OR owner_id IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE lead_automation_settings ADD CONSTRAINT lead_automation_sla_check CHECK ('
            .'sla_minutes IS NULL OR sla_minutes BETWEEN 1 AND 10080)'
        );
        DB::statement("ALTER TABLE lead_automation_settings ADD CONSTRAINT lead_automation_priority_check CHECK (task_priority IN ('low','normal','high','urgent'))");

        Schema::create('lead_round_robin', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->string('assignment_reason', 16)->nullable();
        });
        DB::statement(sprintf(
            "ALTER TABLE leads ADD CONSTRAINT leads_assignment_reason_check CHECK (assignment_reason IS NULL OR assignment_reason IN ('%s'))",
            implode("','", AssignmentReason::values()),
        ));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", NotificationKind::values()),
        ));
    }

    public function down(): void
    {
        $added = ['lead_sla_approaching', 'lead_sla_overdue', 'lead_qualified', 'lead_appointment_booked', 'lead_sale_completed'];

        DB::table('notifications')->whereIn('kind', $added)->delete();
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", array_values(array_diff(NotificationKind::values(), $added))),
        ));

        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_assignment_reason_check');
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('assignment_reason');
        });

        Schema::dropIfExists('lead_round_robin');
        Schema::dropIfExists('lead_automation_settings');
    }
};
