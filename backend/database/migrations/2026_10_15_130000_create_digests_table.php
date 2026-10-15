<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One summary per person per kind per day — the alternative to eight notifications.
     *
     * "8 campaigns need attention" is one thing to read; eight separate bell entries are eight things to
     * dismiss, and teach a person to stop looking at the bell at all. A digest is stored (not sent) so the
     * surface can show it and a later delivery channel can send exactly this. The unique key makes the
     * scheduled run idempotent: running the same morning's job twice produces one digest.
     */
    public function up(): void
    {
        Schema::create('digests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->date('period_on');
            $table->unsignedInteger('total');
            $table->jsonb('summary');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'workspace_id', 'kind', 'period_on']);
            $table->index(['user_id', 'workspace_id', 'period_on']);
        });

        DB::statement("ALTER TABLE digests ADD CONSTRAINT digests_kind_check CHECK (kind IN ('morning_campaigns','daily_budget','weekly_projects'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('digests');
    }
};
