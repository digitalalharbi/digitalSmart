<?php

declare(strict_types=1);

use App\Domain\Advertising\Launch\ActivationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Creating and switching on are separate acts, each with its own record. A campaign is active here only once the platform has
 * been read back saying so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_launch_executions', function (Blueprint $table): void {
            $table->string('activation_status', 24)->nullable();
            $table->foreignUuid('activation_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('activation_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->jsonb('activation_readback')->nullable();
            $table->text('activation_message')->nullable();
        });
        DB::statement(sprintf("ALTER TABLE campaign_launch_executions ADD CONSTRAINT campaign_launch_executions_activation_check CHECK (activation_status IS NULL OR activation_status IN ('%s'))", implode("','", ActivationStatus::values())));
        /* Only a verified creation can be switched on, and active means read back. */
        DB::statement("ALTER TABLE campaign_launch_executions ADD CONSTRAINT campaign_launch_executions_activation_verified_check CHECK (activation_status IS NULL OR status = 'verified')");
        DB::statement("ALTER TABLE campaign_launch_executions ADD CONSTRAINT campaign_launch_executions_active_readback_check CHECK (activation_status <> 'active' OR (activated_at IS NOT NULL AND activation_readback IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campaign_launch_executions DROP CONSTRAINT campaign_launch_executions_active_readback_check');
        DB::statement('ALTER TABLE campaign_launch_executions DROP CONSTRAINT campaign_launch_executions_activation_verified_check');
        DB::statement('ALTER TABLE campaign_launch_executions DROP CONSTRAINT campaign_launch_executions_activation_check');
        Schema::table('campaign_launch_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('activation_requested_by');
            $table->dropConstrainedForeignId('activation_approved_by');
            $table->dropColumn(['activation_status', 'activated_at', 'activation_readback', 'activation_message']);
        });
    }
};
