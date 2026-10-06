<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The measurement foundation: what measures a project, what counts as a conversion there,
 * and which raw event names mean which of those.
 *
 * Three tables and one deliberate absence. There is no table of individual events: storing
 * every `page_view` from every property would be a firehose this product has no use for,
 * and the questions it needs answered — what has this source seen, when did it last see it,
 * is the thing we promised to measure arriving — are answered by a per-source, per-name
 * aggregate. That aggregate is honest about being one.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A measurement source is one thing that measures: a GA4 property, a GTM container,
         * an ad platform's pixel, a store, a CRM. It is bound to a client and optionally to
         * a project, for the same reason a budget is — an agency agrees measurement for a
         * client before deciding which project uses it.
         */
        Schema::create('measurement_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Which platform, from the provider registry — nullable, because a website form
             * or a manual figure has no provider and pretending otherwise would put a
             * made-up slug in a column the registry validates.
             */
            $table->string('provider')->nullable();
            $table->string('kind');

            /** The provider's own identifier: a GA4 property id, a GTM container id, a store id. */
            $table->string('external_id');
            $table->string('name');

            /*
             * The connection this source is read through, where there is one. Nulled rather
             * than cascaded on disconnection: the source and everything observed through it
             * stay, because last week's figures do not stop being true when a token is
             * revoked.
             */
            $table->foreignUuid('provider_connection_id')
                ->nullable()->constrained('provider_connections')->nullOnDelete();

            $table->timestamp('last_synced_at')->nullable();
            /*
             * The two timestamps answer different questions. A property that synced an hour
             * ago but has seen no event for three days is working perfectly and measuring
             * nothing, which is the failure this whole area exists to catch.
             */
            $table->timestamp('last_event_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'project_id']);
            $table->index(['workspace_id', 'kind']);

            // One source per platform identifier per client: two rows for one GA4 property
            // would double every count read through it.
            $table->unique(['workspace_id', 'client_id', 'provider', 'external_id'], 'measurement_sources_unique');
        });

        DB::statement(
            'ALTER TABLE measurement_sources ADD CONSTRAINT measurement_sources_kind_known CHECK ('
            ."kind IN ('analytics', 'tag_manager', 'ad_platform', 'store', 'crm', 'website', 'manual'))"
        );

        /*
         * The conversion registry: what counts as success on this project. Per project
         * rather than per workspace, because two clients of one agency mean different things
         * by "lead" and a shared list would force one of them to be wrong.
         */
        Schema::create('event_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('normalized_event');
            $table->text('description')->nullable();

            /*
             * Whether this is a conversion at all, and whether it is *the* one. Separate
             * flags: a project has many conversions and at most a few primary ones, and a
             * report that cannot say which matters most cannot rank anything.
             */
            $table->boolean('is_conversion')->default(true);
            $table->boolean('is_primary')->default(false);

            /*
             * Whether a value is expected with this event. Declared rather than inferred, so
             * "purchases arriving with no revenue" is a detectable fault instead of a column
             * of nulls nobody notices.
             */
            $table->boolean('expects_value')->default(false);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'project_id', 'is_primary']);

            $table->unique(['workspace_id', 'client_id', 'project_id', 'name'], 'event_definitions_unique');
        });

        DB::statement(
            'ALTER TABLE event_definitions ADD CONSTRAINT event_definitions_primary_is_conversion CHECK ('
            .'is_primary = false OR is_conversion = true)'
        );

        /*
         * Raw name → definition. The mapping is the whole point of the measurement layer:
         * nothing in this product infers meaning from an event name, because one property's
         * `contact` is a form and another's is a phone tap.
         */
        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_definition_id')->constrained('event_definitions')->cascadeOnDelete();
            /*
             * Which source this name arrives from. Nullable means "from any source": a
             * client who fires `purchase` identically everywhere should not have to map it
             * four times.
             */
            $table->foreignUuid('measurement_source_id')
                ->nullable()->constrained('measurement_sources')->cascadeOnDelete();

            $table->string('raw_event_name');
            $table->timestamps();

            $table->index(['workspace_id', 'raw_event_name']);
            $table->index(['event_definition_id']);
        });

        /*
         * Per source, per raw name, per day. An aggregate, and named like one.
         *
         * This is what makes the health checks possible — "has the thing we promised to
         * measure arrived" — without storing an event per visitor. The value column is
         * nullable because most events have no value, and a zero would make "no revenue
         * reported" indistinguishable from "a free order".
         */
        Schema::create('observed_event_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('measurement_source_id')->constrained('measurement_sources')->cascadeOnDelete();

            $table->string('raw_event_name');
            $table->date('date');
            $table->bigInteger('event_count');
            $table->bigInteger('value_minor')->nullable();
            $table->string('currency', 3)->nullable();
            /** The honesty label the rest of the product uses, carried on the aggregate. */
            $table->string('source')->default('synced');
            $table->timestamps();

            $table->unique(
                ['measurement_source_id', 'raw_event_name', 'date'],
                'observed_event_counts_unique',
            );
            $table->index(['workspace_id', 'date']);
        });

        DB::statement(
            'ALTER TABLE observed_event_counts ADD CONSTRAINT observed_event_counts_non_negative CHECK ('
            .'event_count >= 0 AND (value_minor IS NULL OR value_minor >= 0))'
        );
        /*
         * A value without a currency is a number without a unit, and the first thing anyone
         * would do with it is add it to another one.
         */
        DB::statement(
            'ALTER TABLE observed_event_counts ADD CONSTRAINT observed_event_counts_value_has_currency CHECK ('
            .'value_minor IS NULL OR currency IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('observed_event_counts');
        Schema::dropIfExists('event_mappings');
        Schema::dropIfExists('event_definitions');
        Schema::dropIfExists('measurement_sources');
    }
};
