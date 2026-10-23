<?php

declare(strict_types=1);

use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requests for an account, made from the public landing page by somebody who has none.
 *
 * Tied to one workspace — the agency that runs this installation and chose to take requests — and read only by that
 * workspace's administrators, so one agency's prospects are never visible to another. Nothing here is an account: it is
 * a person's name, how to reach them and what they said they want, waiting for somebody to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('need', 24);
            $table->text('message')->nullable();
            $table->string('locale', 5)->default('ar');
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'email', 'created_at']);
        });

        DB::statement(sprintf(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_need_check CHECK (need IN ('%s'))",
            implode("','", AccessRequestNeed::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_status_check CHECK (status IN ('%s'))",
            implode("','", AccessRequestStatus::values()),
        ));
        /* A decision names who made it and when, and a request nobody decided names nobody. */
        DB::statement(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_decided_by_check CHECK (\n"
            ."  (status IN ('approved','declined')) = (reviewed_at IS NOT NULL)\n"
            .')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
