<?php

declare(strict_types=1);

use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\MessageAudience;
use App\Domain\Operations\StatedUrgency;
use App\Domain\Operations\TaskSubjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client requests: what a client asked for, what was said back, and who promised what.
 *
 * Three rules in the schema, each with an obvious wrong version:
 *
 *  - **A promised date carries the person who promised it.** `(promised_at IS NULL) = (promised_by IS NULL)`. A
 *    date an agency committed to is a date somebody committed to; one that appeared without a name behind it is
 *    the date a client quotes in a meeting and nobody can account for.
 *  - **Declining says why, and asking for information says what.** Two CHECKs. The decline reason is shown to
 *    the client — a request that vanished from an intake queue with nobody told why is the worst thing this
 *    module could do — and "we need something from you" with no statement of what is a message nobody can act on.
 *  - **A message's audience is explicit.** `messages.audience` has no default. An agency's internal note about a
 *    client reaching that client ends a relationship, and a default is how it happens.
 *
 * `stated_urgency` is deliberately **not** a priority. It is what the client said, and the agency's own priority
 * lives on the tasks this request spawns — see `StatedUrgency`. One column for both would let a client set an
 * agency's priorities by typing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /** Required. A request with no client is not a client request. */
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('kind');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('state')->default(ClientRequestState::Submitted->value);
            /** What the client said, never the board's priority. See the note above. */
            $table->string('stated_urgency')->default(StatedUrgency::WheneverYouCan->value);

            /** What the client asked for by, if they said. Their wish, not a commitment. */
            $table->date('wanted_by')->nullable();

            /*
             * What the agency agreed to, and who agreed it. Both or neither — a CHECK enforces it.
             */
            $table->date('promised_at')->nullable();
            $table->foreignUuid('promised_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();

            /** Shown to the client. The whole point of recording it. */
            $table->text('declined_reason')->nullable();
            /** What is needed from the client. Also shown to them. */
            $table->text('needed_information')->nullable();

            $table->timestamp('state_changed_at')->nullable();
            /** When somebody first said anything at all. The figure a client's patience is measured against. */
            $table->timestamp('first_answered_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'state']);
            $table->index(['workspace_id', 'client_id', 'state']);
            $table->index(['workspace_id', 'promised_at']);
            $table->index(['workspace_id', 'kind']);
        });

        DB::statement(sprintf(
            "ALTER TABLE client_requests ADD CONSTRAINT client_requests_state_check CHECK (state IN ('%s'))",
            implode("','", ClientRequestState::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE client_requests ADD CONSTRAINT client_requests_kind_check CHECK (kind IN ('%s'))",
            implode("','", ClientRequestKind::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE client_requests ADD CONSTRAINT client_requests_urgency_check CHECK (stated_urgency IN ('%s'))",
            implode("','", StatedUrgency::values()),
        ));

        DB::statement(
            "ALTER TABLE client_requests ADD CONSTRAINT client_requests_decline_has_reason CHECK (\n"
            ."  state <> 'declined' OR (declined_reason IS NOT NULL AND btrim(declined_reason) <> '')\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE client_requests ADD CONSTRAINT client_requests_question_is_stated CHECK (\n"
            ."  state <> 'needs_information'\n"
            ."  OR (needed_information IS NOT NULL AND btrim(needed_information) <> '')\n"
            .')'
        );

        // A date with nobody behind it is one the client will quote and nobody will own.
        DB::statement(
            'ALTER TABLE client_requests ADD CONSTRAINT client_requests_promise_has_a_person CHECK ('."\n"
            .'  (promised_at IS NULL) = (promised_by IS NULL)'."\n"
            .')'
        );

        DB::statement(
            "ALTER TABLE client_requests ADD CONSTRAINT client_requests_title_present CHECK (btrim(title) <> '')"
        );

        Schema::create('client_request_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_request_id')->constrained('client_requests')->cascadeOnDelete();

            /** No default. See the note at the top of this migration. */
            $table->string('audience');
            $table->text('body');
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            /*
             * Whether the author was writing as the client. Recorded rather than derived from their role: a
             * member's role can change, and a message's side of the conversation cannot.
             */
            $table->boolean('is_from_client')->default(false);

            $table->timestamps();

            $table->index(['workspace_id', 'client_request_id']);
            $table->index(['workspace_id', 'audience']);
        });

        DB::statement(sprintf(
            "ALTER TABLE client_request_messages ADD CONSTRAINT client_request_messages_audience_check CHECK (audience IN ('%s'))",
            implode("','", MessageAudience::values()),
        ));

        DB::statement(
            "ALTER TABLE client_request_messages ADD CONSTRAINT client_request_messages_body_present CHECK (btrim(body) <> '')"
        );

        /*
         * A message from the client is always visible to the client. Enforced rather than trusted: an interface
         * bug that filed a client's own words as internal would hide them from the person who wrote them.
         */
        DB::statement(
            "ALTER TABLE client_request_messages ADD CONSTRAINT client_request_messages_client_sees_their_own CHECK (\n"
            ."  is_from_client = false OR audience = 'client'\n"
            .')'
        );

        /*
         * Tasks may now be about a client request, which is how an intake queue stays a conversation rather than
         * becoming a second task board. The CHECK is rebuilt from the enum rather than extended by hand.
         */
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_subject_type_check');
        DB::statement(sprintf(
            "ALTER TABLE tasks ADD CONSTRAINT tasks_subject_type_check CHECK (\n"
            ."  subject_type IS NULL OR subject_type IN ('%s')\n"
            .')',
            implode("','", TaskSubjectType::values()),
        ));

        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT approval_requests_subject_type_check');
        DB::statement(sprintf(
            "ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_subject_type_check CHECK (\n"
            ."  subject_type IS NULL OR subject_type IN ('%s')\n"
            .')',
            implode("','", TaskSubjectType::values()),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('client_request_messages');
        Schema::dropIfExists('client_requests');
    }
};
