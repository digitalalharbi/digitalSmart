<?php

declare(strict_types=1);

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads: people who did something, and what happened next.
 *
 * **A lead is a person, not an event.** That is the whole design. The measurement layer already counts
 * clicks and form starts, and those are *signals*; a row here means somebody submitted a form, sent a
 * message, answered a call or placed an order. Nothing in this product creates one of these from a counted
 * signal, and the source enum has no `whatsapp_click` to make it possible.
 *
 * The distinction is not pedantry. An agency reporting WhatsApp clicks as leads reports a cost per lead a
 * fraction of the real one, wins the next month's budget on it, and cannot explain afterwards why the
 * client's sales did not move.
 *
 * Two tables: the lead, and what has happened to it. The history is separate because "when was this
 * qualified, and by whom" is a question about events, and keeping only the current stage makes a pipeline
 * report possible and a pipeline *review* impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            /*
             * Required. A lead with no client is an enquiry nobody can act on and nobody can report.
             */
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('source_kind');
            /** Which form, which number, which store — whatever identifies the specific route in. */
            $table->string('source_detail')->nullable();
            /*
             * Where a lead came from an order, so a store customer and a lead are the same person rather
             * than two counts of them.
             */
            $table->foreignUuid('store_order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            /** The measurement source that reported it, where one did. */
            $table->foreignUuid('measurement_source_id')->nullable()
                ->constrained('measurement_sources')->nullOnDelete();
            /** The provider's own id, so a re-read recognises the same lead. */
            $table->string('external_id')->nullable();

            $table->string('name')->nullable();
            /*
             * The client's prospect's contact details. Personal data belonging to the client, never
             * serialised into a client-portal response, and the reason `docs/SECURITY.md` has a retention
             * note.
             */
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            /*
             * Normalised forms, used only to recognise the same person twice. Indexed rather than unique:
             * one person may legitimately enquire twice, months apart, about different things — and
             * refusing the second would lose a real lead.
             */
            $table->string('email_key')->nullable();
            $table->string('phone_key')->nullable();
            $table->text('message')->nullable();

            $table->string('stage')->default(LeadStage::New->value);
            /** Why a human disqualified it. Empty for every other stage. */
            $table->string('disqualified_reason')->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();

            /** What the lead is worth, where somebody has said. Never guessed from an average. */
            $table->bigInteger('value_minor')->nullable();
            $table->string('currency', 3)->nullable();

            /*
             * What the lead itself carried about where it came from, with the same evidence vocabulary the
             * orders use. One rule for both, so a cross-surface report cannot compare two different
             * standards of proof.
             */
            $table->string('attribution_evidence')->default(AttributionEvidence::None->value);
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('click_identifier')->nullable();
            $table->string('landing_page')->nullable();
            $table->string('referrer')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'received_at']);
            $table->index(['workspace_id', 'client_id', 'stage']);
            $table->index(['workspace_id', 'phone_key']);
            $table->index(['workspace_id', 'email_key']);
            /*
             * One lead per provider record. Partial, because most leads have no external id — a form
             * submission this product received itself has nothing to be unique on — and a unique index
             * over nulls would be no constraint at all in some engines and a wrong one in others.
             */
            $table->unique(['workspace_id', 'source_kind', 'external_id'], 'leads_external_unique');
        });

        DB::statement(sprintf(
            "ALTER TABLE leads ADD CONSTRAINT leads_stage_check CHECK (stage IN ('%s'))",
            implode("','", LeadStage::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE leads ADD CONSTRAINT leads_source_kind_check CHECK (source_kind IN ('%s'))",
            implode("','", LeadSourceKind::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE leads ADD CONSTRAINT leads_evidence_check CHECK (attribution_evidence IN ('%s'))",
            implode("','", AttributionEvidence::values()),
        ));

        /*
         * The same constraint the orders table carries, for the same reason: a lead claiming campaign
         * parameters with no parameters on it would let a client be told which campaign produced their
         * enquiries on the strength of nothing.
         */
        DB::statement(
            'ALTER TABLE leads ADD CONSTRAINT leads_evidence_is_backed CHECK ('
            ."attribution_evidence = 'none' OR utm_source IS NOT NULL OR utm_medium IS NOT NULL "
            .'OR utm_campaign IS NOT NULL OR click_identifier IS NOT NULL OR referrer IS NOT NULL)'
        );

        /** A value with no currency is a number with no unit. */
        DB::statement(
            'ALTER TABLE leads ADD CONSTRAINT leads_value_check CHECK ('
            .'value_minor IS NULL OR (value_minor >= 0 AND currency IS NOT NULL))'
        );

        /*
         * A disqualification has to say why. The one stage where a reason is not optional, because
         * "disqualified" with no reason is a lead somebody quietly gave up on — and a client paying for
         * leads deserves to know which it was.
         */
        DB::statement(
            'ALTER TABLE leads ADD CONSTRAINT leads_disqualified_has_reason CHECK ('
            ."stage <> 'disqualified' OR disqualified_reason IS NOT NULL)"
        );

        /*
         * A closed lead has a closing time, and an open one does not. Without this the figure that matters
         * most — how long a pipeline takes — is computable for some rows and not others, which is worse
         * than not having it.
         */
        DB::statement(
            'ALTER TABLE leads ADD CONSTRAINT leads_closed_has_time CHECK ('
            ."(stage IN ('won','lost','disqualified')) = (closed_at IS NOT NULL))"
        );

        Schema::create('lead_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();
            /** Null for something the system recorded rather than a person. */
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type');
            $table->string('from_stage')->nullable();
            $table->string('to_stage')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['lead_id', 'occurred_at']);
        });

        DB::statement(
            'ALTER TABLE lead_events ADD CONSTRAINT lead_events_type_check CHECK ('
            ."type IN ('received','contacted','stage_changed','note_added','assigned','value_set','merged'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_events');
        Schema::dropIfExists('leads');
    }
};
