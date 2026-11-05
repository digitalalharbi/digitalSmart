<?php

declare(strict_types=1);

use App\Domain\Reporting\EmailCadence;
use App\Domain\Reporting\EmailDeliveryState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A client-safe report emailed to somebody outside the agency, on a rhythm, with a record of every attempt.
 *
 * Two tables because they answer different questions. A subscription says who is to receive which report, how often,
 * and who recorded that they agreed to it. A delivery says what happened to one period's email — and its unique key on
 * (subscription, period) is what makes "never twice for one period" a constraint rather than a hope.
 *
 * The address is a person's contact detail outside the workspace's membership, so it is unique per report, never
 * shared between workspaces, and removed with the subscription. The unsubscribe token is stored as a hash, like a
 * share link's: the row must not be a place the credential can be read back from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_email_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('recipient_email', 254);
            $table->string('recipient_name', 120)->nullable();
            $table->string('locale', 2)->default('ar');
            $table->string('cadence', 12);
            $table->string('status', 16)->default('active');
            /** Why this address may be written to: who in the agency recorded the recipient's agreement, and how. */
            $table->text('consent_note');
            $table->timestampTz('consented_at');
            $table->char('unsubscribe_token_hash', 64)->unique();
            $table->text('halted_reason')->nullable();
            /** The member whose access every send is re-checked against. */
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'report_id', 'recipient_email'], 'report_email_subscriptions_recipient_unique');
            $table->index(['status', 'cadence']);
        });

        DB::statement(sprintf(
            "ALTER TABLE report_email_subscriptions ADD CONSTRAINT report_email_subscriptions_cadence_check CHECK (cadence IN ('%s'))",
            implode("','", EmailCadence::values()),
        ));
        DB::statement(
            "ALTER TABLE report_email_subscriptions ADD CONSTRAINT report_email_subscriptions_status_check CHECK (status IN ('active','paused','unsubscribed','halted'))",
        );
        DB::statement(
            'ALTER TABLE report_email_subscriptions ADD CONSTRAINT report_email_subscriptions_consent_check CHECK (length(btrim(consent_note)) > 0)',
        );

        Schema::create('report_email_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('report_email_subscriptions')->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->string('state', 24);
            $table->text('detail')->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->foreignUuid('share_id')->nullable()->constrained('report_shares')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['subscription_id', 'period_key'], 'report_email_deliveries_period_unique');
            $table->index(['workspace_id', 'state']);
        });

        DB::statement(sprintf(
            "ALTER TABLE report_email_deliveries ADD CONSTRAINT report_email_deliveries_state_check CHECK (state IN ('%s'))",
            implode("','", EmailDeliveryState::values()),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('report_email_deliveries');
        Schema::dropIfExists('report_email_subscriptions');
    }
};
