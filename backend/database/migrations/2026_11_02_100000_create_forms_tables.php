<?php

declare(strict_types=1);

use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\Form\FormFieldType;
use App\Domain\Tracking\Form\FormStatus;
use App\Domain\Tracking\TrafficClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms a project publishes, and what people submit through them.
 *
 * `form_submissions` is the system of record for a submission: not a spreadsheet, not the lead. A lead is created from it
 * only when the form says so, and the submission keeps the answers as they were given even if the lead is later merged.
 * Leads gain a small `custom` map for the project-specific answers a form collects (city, budget, property type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('public_token', 24)->unique();
            $table->string('name', 160);
            $table->string('status', 12)->default('draft');
            $table->string('default_locale', 2)->default('ar');
            $table->boolean('creates_lead')->default(false);
            $table->string('submit_label_ar', 80)->nullable();
            $table->string('submit_label_en', 80)->nullable();
            $table->string('thank_you_ar', 600)->nullable();
            $table->string('thank_you_en', 600)->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->string('theme', 8)->default('auto');
            $table->string('accent', 7)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['workspace_id', 'project_id', 'status']);
        });

        Schema::create('form_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('field_key', 40);
            $table->string('type', 12);
            $table->unsignedSmallInteger('position');
            $table->string('label_ar', 190)->nullable();
            $table->string('label_en', 190)->nullable();
            $table->string('placeholder_ar', 190)->nullable();
            $table->string('placeholder_en', 190)->nullable();
            $table->string('help_ar', 300)->nullable();
            $table->string('help_en', 300)->nullable();
            $table->boolean('required')->default(false);
            $table->string('default_value', 190)->nullable();
            /* [{value, label_ar, label_en}] */
            $table->jsonb('options')->default('[]');
            /* {min_length, max_length, min, max, pattern?} — only the keys the type understands */
            $table->jsonb('validation')->default('{}');
            /* show-when: {field, operator, value} or null (always shown) */
            $table->jsonb('visibility')->nullable();
            /* "name" | "email" | "phone" | "message" | "custom.<key>" | null */
            $table->string('maps_to', 60)->nullable();
            $table->timestampsTz();

            $table->unique(['form_id', 'field_key']);
            $table->index(['form_id', 'position']);
        });

        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('event_id', 40);
            $table->string('visitor_id', 32)->nullable();
            $table->string('session_id', 32)->nullable();
            $table->string('touchpoint_id', 32)->nullable();
            $table->foreignUuid('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->jsonb('utm')->default('{}');
            $table->jsonb('click_ids')->default('{}');
            /* field_key => answer, exactly the fields the form defined and only the ones that were visible */
            $table->jsonb('payload')->default('{}');
            $table->string('consent_state', 16)->default('unknown');
            $table->string('traffic_class', 16)->default('valid');
            $table->string('locale', 2)->default('ar');
            $table->timestampTz('submitted_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['project_id', 'event_id']);
            $table->index(['form_id', 'submitted_at']);
            $table->index(['tracking_link_id', 'submitted_at']);
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->jsonb('custom')->default('{}');
        });

        $in = static fn (array $values): string => "'".implode("','", $values)."'";

        DB::statement('ALTER TABLE forms ADD CONSTRAINT forms_status_check CHECK (status IN ('.$in(FormStatus::values()).'))');
        DB::statement("ALTER TABLE forms ADD CONSTRAINT forms_theme_check CHECK (theme IN ('auto','light','dark'))");
        DB::statement("ALTER TABLE forms ADD CONSTRAINT forms_locale_check CHECK (default_locale IN ('ar','en'))");
        DB::statement('ALTER TABLE form_fields ADD CONSTRAINT form_fields_type_check CHECK (type IN ('.$in(FormFieldType::values()).'))');
        DB::statement('ALTER TABLE form_submissions ADD CONSTRAINT form_submissions_consent_check CHECK (consent_state IN ('.$in(ConsentState::values()).'))');
        DB::statement('ALTER TABLE form_submissions ADD CONSTRAINT form_submissions_class_check CHECK (traffic_class IN ('.$in(TrafficClass::values()).'))');
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('custom');
        });
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }
};
