<?php

declare(strict_types=1);

use App\Domain\Tracking\Funnel\FunnelSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Funnels a project defines, and two moments in a lead's life this product had no word for.
 *
 * An appointment and an opportunity are things a person records against a lead; they are `lead_events`, like a note or a
 * contact, so the pipeline keeps one history. A funnel is a list of stages, each naming where its count comes from — never
 * a free-form query, so a funnel cannot be made to divide by something misleading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            /* [{source, label_ar, label_en, required}] in order */
            $table->jsonb('stages');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['project_id', 'name']);
        });

        Schema::table('form_submissions', function (Blueprint $table): void {
            $table->index('session_id');
        });

        DB::statement('ALTER TABLE lead_events DROP CONSTRAINT lead_events_type_check');
        DB::statement(
            'ALTER TABLE lead_events ADD CONSTRAINT lead_events_type_check CHECK ('
            ."type IN ('received','contacted','stage_changed','note_added','assigned','value_set','merged','appointment_booked','opportunity_created'))"
        );

        /* Referenced so the vocabulary the stages may use stays one list: the enum. */
        DB::statement(sprintf(
            "COMMENT ON COLUMN funnels.stages IS 'stage sources: %s'",
            implode(',', FunnelSource::values()),
        ));
    }

    public function down(): void
    {
        DB::table('lead_events')->whereIn('type', ['appointment_booked', 'opportunity_created'])->delete();
        DB::statement('ALTER TABLE lead_events DROP CONSTRAINT lead_events_type_check');
        DB::statement(
            'ALTER TABLE lead_events ADD CONSTRAINT lead_events_type_check CHECK ('
            ."type IN ('received','contacted','stage_changed','note_added','assigned','value_set','merged'))"
        );
        Schema::table('form_submissions', function (Blueprint $table): void {
            $table->dropIndex(['session_id']);
        });
        Schema::dropIfExists('funnels');
    }
};
