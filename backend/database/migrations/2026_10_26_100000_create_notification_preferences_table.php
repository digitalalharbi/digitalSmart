<?php

declare(strict_types=1);

use App\Domain\Notifications\DigestChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person per workspace: which digests they want, where, when, about what, and which tabs are muted.
 *
 * No row means the defaults — every digest, in the app, at nine in the workspace's own morning, about everything
 * they can see — so nobody has to configure anything to be told what changed. `timezone` null means "the
 * workspace's"; `hour` is local to whichever zone applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('daily')->default(true);
            $table->boolean('weekly')->default(true);
            $table->boolean('monthly')->default(true);
            $table->jsonb('channels')->default('["in_app"]');
            $table->string('timezone', 64)->nullable();
            $table->unsignedSmallInteger('hour')->default(9);
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('project_ids')->nullable();
            $table->jsonb('muted_categories')->default('[]');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);
        });

        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_hour_check CHECK (hour BETWEEN 0 AND 23)');
        DB::statement(sprintf(
            "ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_channels_check CHECK (channels <@ '%s'::jsonb)",
            json_encode(DigestChannel::values(), JSON_THROW_ON_ERROR),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
