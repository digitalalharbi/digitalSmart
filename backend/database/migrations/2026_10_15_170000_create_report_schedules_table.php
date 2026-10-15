<?php

declare(strict_types=1);

use App\Domain\Export\ExportKind;
use App\Domain\Export\ScheduleCadence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A report made again on a rhythm, for one person, as that person.
 *
 * `next_run_at` null is a halted schedule — see `last_error` for why. Times are UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('kind');
            $table->string('cadence');
            $table->unsignedSmallInteger('hour');
            $table->unsignedSmallInteger('weekday')->nullable();
            $table->unsignedSmallInteger('day_of_month')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index('next_run_at');
            $table->index(['workspace_id', 'user_id']);
        });

        DB::statement(sprintf("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_kind_check CHECK (kind IN ('%s'))", implode("','", ExportKind::values())));
        DB::statement(sprintf("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_cadence_check CHECK (cadence IN ('%s'))", implode("','", ScheduleCadence::values())));
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_hour_check CHECK (hour BETWEEN 0 AND 23)');
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_weekday_check CHECK (weekday IS NULL OR weekday BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_dom_check CHECK (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 28)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
