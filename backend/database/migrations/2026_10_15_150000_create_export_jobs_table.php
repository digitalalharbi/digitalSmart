<?php

declare(strict_types=1);

use App\Domain\Export\ExportKind;
use App\Domain\Export\ExportStatus;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Background exports. A row is the whole record of one: who asked, what, how it went, where the file is.
 *
 * `storage_path` is nulled when retention lapses and the row stays, so "what did I export last month" still
 * has an answer after the file does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->uuid('subject_id');
            $table->string('status');
            $table->string('filename')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'user_id', 'requested_at']);
            $table->index('expires_at');
        });

        DB::statement(sprintf("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_kind_check CHECK (kind IN ('%s'))", implode("','", ExportKind::values())));
        DB::statement(sprintf("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_status_check CHECK (status IN ('%s'))", implode("','", ExportStatus::values())));
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_ready_has_file CHECK (status <> 'ready' OR (filename IS NOT NULL AND finished_at IS NOT NULL AND expires_at IS NOT NULL))");

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))", implode("','", NotificationKind::values())));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))", implode("','", NotificationSubject::values())));
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('kind', NotificationKind::ExportReady->value)
            ->orWhere('subject_type', NotificationSubject::Export->value)
            ->delete();

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))", implode("','", array_values(array_filter(NotificationKind::values(), static fn (string $k): bool => $k !== NotificationKind::ExportReady->value)))));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))", implode("','", array_values(array_filter(NotificationSubject::values(), static fn (string $s): bool => $s !== NotificationSubject::Export->value)))));

        Schema::dropIfExists('export_jobs');
    }
};
