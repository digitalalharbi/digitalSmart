<?php

declare(strict_types=1);

use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\LinkStatus;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First-party tracking: the links an advertisement points at, and the events that follow a click.
 *
 * Every row belongs to a project, and a project belongs to a client and a workspace, so tracking is isolated the same way
 * every other piece of work is. Public identifiers (`public_token`, visitor, session, touchpoint, event) are random and
 * carry no meaning; the UUID primary keys stay inside the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_link_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            /* utm parameter => pattern with {{variables}} */
            $table->jsonb('patterns');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('tracking_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('public_token', 24)->unique();
            $table->string('name', 160);
            $table->string('platform', 32)->nullable();
            $table->foreignUuid('external_account_id')->nullable()->constrained('external_accounts')->nullOnDelete();
            $table->string('campaign', 190)->nullable();
            $table->string('ad_group', 190)->nullable();
            $table->string('ad', 190)->nullable();
            $table->string('creative', 190)->nullable();
            $table->string('channel', 24)->default('paid');
            $table->string('destination_url', 2048);
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 190)->nullable();
            $table->string('utm_content', 190)->nullable();
            $table->string('utm_term', 190)->nullable();
            $table->jsonb('custom_params')->default('{}');
            $table->string('objective', 24)->nullable();
            $table->string('status', 12)->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['workspace_id', 'project_id', 'status']);
            $table->index(['workspace_id', 'client_id']);
        });

        Schema::create('tracking_sessions', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->string('touchpoint_id', 32)->unique();
            $table->string('visitor_id', 32);
            $table->string('platform', 32)->nullable();
            $table->string('channel', 24)->default('paid');
            $table->string('referrer_host', 190)->nullable();
            $table->string('device_class', 12)->default('unknown');
            $table->string('browser_class', 12)->default('unknown');
            $table->string('consent_state', 16)->default('unknown');
            $table->string('traffic_class', 16)->default('valid');
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');

            $table->index(['project_id', 'started_at']);
            $table->index(['visitor_id']);
            $table->index(['tracking_link_id', 'started_at']);
        });

        Schema::create('tracking_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            /* The dedup key: the same event delivered twice by a browser that retried is one event. */
            $table->string('event_id', 40);
            $table->string('name', 40);
            $table->string('visitor_id', 32);
            $table->string('session_id', 32)->nullable();
            $table->string('touchpoint_id', 32)->nullable();
            $table->string('source', 16)->default('first_party');
            $table->string('traffic_class', 16)->default('valid');
            $table->string('consent_state', 16)->default('unknown');
            $table->string('landing_path', 512)->nullable();
            $table->string('referrer_host', 190)->nullable();
            $table->jsonb('utm')->default('{}');
            $table->jsonb('click_ids')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['project_id', 'event_id']);
            $table->index(['project_id', 'name', 'occurred_at']);
            $table->index(['tracking_link_id', 'name', 'occurred_at']);
            $table->index(['session_id']);
        });

        $in = static fn (array $values): string => "'".implode("','", $values)."'";

        DB::statement('ALTER TABLE tracking_links ADD CONSTRAINT tracking_links_status_check CHECK (status IN ('.$in(LinkStatus::values()).'))');
        DB::statement("ALTER TABLE tracking_links ADD CONSTRAINT tracking_links_channel_check CHECK (channel IN ('paid','organic','email','sms','offline_qr','referral','direct','other'))");
        DB::statement('ALTER TABLE tracking_sessions ADD CONSTRAINT tracking_sessions_consent_check CHECK (consent_state IN ('.$in(ConsentState::values()).'))');
        DB::statement('ALTER TABLE tracking_sessions ADD CONSTRAINT tracking_sessions_class_check CHECK (traffic_class IN ('.$in(TrafficClass::values()).'))');
        DB::statement('ALTER TABLE tracking_events ADD CONSTRAINT tracking_events_name_check CHECK (name IN ('.$in(TrackingEventName::values()).'))');
        DB::statement('ALTER TABLE tracking_events ADD CONSTRAINT tracking_events_class_check CHECK (traffic_class IN ('.$in(TrafficClass::values()).'))');
        DB::statement('ALTER TABLE tracking_events ADD CONSTRAINT tracking_events_consent_check CHECK (consent_state IN ('.$in(ConsentState::values()).'))');
        DB::statement("ALTER TABLE tracking_events ADD CONSTRAINT tracking_events_source_check CHECK (source IN ('first_party','server','test'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('tracking_sessions');
        Schema::dropIfExists('tracking_links');
        Schema::dropIfExists('tracking_link_templates');
    }
};
