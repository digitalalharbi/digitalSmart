<?php

declare(strict_types=1);

use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestKind;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\PreferredContact;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A request for access becomes a small piece of operations: who owns it, how it was tried, whether it was answered in time, and
 * what became of the person afterwards.
 *
 * Not a second CRM. It is the same `access_requests` table, widened, with a timeline, a response-time promise, and an invitation
 * that — unlike adding a member — creates no account until the invited person accepts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_status_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_decided_by_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_need_check');
        DB::statement('ALTER TABLE access_requests ALTER COLUMN status TYPE varchar(24)');
        DB::statement('ALTER TABLE access_requests ALTER COLUMN need DROP NOT NULL');
        DB::statement('ALTER TABLE access_requests ALTER COLUMN status SET DEFAULT \'new\'');

        DB::table('access_requests')->where('status', 'pending')->update(['status' => 'new']);
        DB::table('access_requests')->where('status', 'declined')->update(['status' => 'rejected']);

        Schema::table('access_requests', function (Blueprint $table): void {
            $table->string('kind', 24)->default('account_access');
            $table->string('job_title', 120)->nullable();
            $table->string('preferred_contact', 12)->default('email');
            $table->string('phone_key', 40)->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_attempt_at')->nullable();
            $table->timestampTz('first_contacted_at')->nullable();
            $table->timestampTz('last_contact_at')->nullable();
            $table->timestampTz('next_follow_up_at')->nullable();
            $table->uuid('possible_duplicate_of')->nullable();
            /* What the client may be told on a rejection, when the person rejecting chose to write one. The reason stays internal. */
            $table->text('client_message')->nullable();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('invitation_shared_at')->nullable();
            $table->timestampTz('invitation_opened_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('account_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_login_at')->nullable();

            $table->index(['workspace_id', 'kind', 'status']);
            $table->index(['workspace_id', 'phone_key']);
            $table->index('account_user_id');
        });

        DB::statement('ALTER TABLE access_requests ADD CONSTRAINT access_requests_possible_duplicate_fk FOREIGN KEY (possible_duplicate_of) REFERENCES access_requests (id) ON DELETE SET NULL');

        $this->constraints();

        Schema::create('access_request_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('access_request_id')->constrained('access_requests')->cascadeOnDelete();
            $table->string('type', 24);
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['access_request_id', 'occurred_at']);
        });
        DB::statement(sprintf(
            "ALTER TABLE access_request_events ADD CONSTRAINT access_request_events_type_check CHECK (type IN ('%s'))",
            implode("','", AccessEventType::values()),
        ));

        Schema::create('access_request_settings', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('response_minutes')->default(60);
            $table->foreignUuid('default_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at');
        });
        DB::statement('ALTER TABLE access_request_settings ADD CONSTRAINT access_request_settings_minutes_check CHECK (response_minutes BETWEEN 1 AND 10080)');

        Schema::create('access_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('access_request_id')->constrained('access_requests')->cascadeOnDelete();
            /* SHA-256 of the token. The token itself is shown once, to the person who made the invitation, and never stored. */
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 12);
            $table->string('email', 190);
            $table->string('name', 120);
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->jsonb('project_ids')->default('[]');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['access_request_id']);
        });

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", NotificationKind::values()),
        ));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))",
            implode("','", NotificationSubject::values()),
        ));
    }

    public function down(): void
    {
        $kinds = ['access_request_received', 'access_request_approaching', 'access_request_overdue', 'access_request_approved', 'access_invitation_pending'];

        DB::table('notifications')->whereIn('kind', $kinds)->delete();
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", array_values(array_diff(NotificationKind::values(), $kinds))),
        ));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))",
            implode("','", array_values(array_filter(NotificationSubject::values(), static fn (string $s): bool => $s !== 'access_request'))),
        ));

        Schema::dropIfExists('access_invitations');
        Schema::dropIfExists('access_request_settings');
        Schema::dropIfExists('access_request_events');

        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_status_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_decided_by_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_need_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT IF EXISTS access_requests_kind_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT IF EXISTS access_requests_preferred_contact_check');
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT access_requests_possible_duplicate_fk');

        Schema::table('access_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'kind', 'job_title', 'preferred_contact', 'phone_key', 'owner_id', 'first_attempt_at', 'first_contacted_at',
                'last_contact_at', 'next_follow_up_at', 'possible_duplicate_of', 'client_message', 'invited_at',
                'invitation_shared_at', 'invitation_opened_at', 'accepted_at', 'account_user_id', 'first_login_at',
            ]);
        });

        DB::table('access_requests')->where('status', 'new')->update(['status' => 'pending']);
        DB::table('access_requests')->where('status', 'rejected')->update(['status' => 'declined']);
        DB::table('access_requests')->whereIn('status', ['waiting_for_reply', 'qualified', 'closed'])->update(['status' => 'contacted']);
        DB::table('access_requests')->whereNull('need')->update(['need' => AccessRequestNeed::SelfManaged->value]);

        DB::statement('ALTER TABLE access_requests ALTER COLUMN need SET NOT NULL');
        DB::statement('ALTER TABLE access_requests ALTER COLUMN status TYPE varchar(16)');
        DB::statement('ALTER TABLE access_requests ALTER COLUMN status SET DEFAULT \'pending\'');
        DB::statement("ALTER TABLE access_requests ADD CONSTRAINT access_requests_need_check CHECK (need IN ('self_managed','agency_clients','ad_services'))");
        DB::statement("ALTER TABLE access_requests ADD CONSTRAINT access_requests_status_check CHECK (status IN ('pending','contacted','approved','declined'))");
        DB::statement("ALTER TABLE access_requests ADD CONSTRAINT access_requests_decided_by_check CHECK ((status IN ('approved','declined')) = (reviewed_at IS NOT NULL))");
    }

    private function constraints(): void
    {
        DB::statement(sprintf(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_status_check CHECK (status IN ('%s'))",
            implode("','", AccessRequestStatus::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_need_check CHECK (need IS NULL OR need IN ('%s'))",
            implode("','", AccessRequestNeed::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_kind_check CHECK (kind IN ('%s'))",
            implode("','", AccessRequestKind::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_preferred_contact_check CHECK (preferred_contact IN ('%s'))",
            implode("','", PreferredContact::values()),
        ));
        /* A decision names when it was made. One way only: an approved request is later closed, and keeps its decision. */
        DB::statement(
            "ALTER TABLE access_requests ADD CONSTRAINT access_requests_decided_by_check CHECK (status NOT IN ('approved','rejected') OR reviewed_at IS NOT NULL)"
        );
    }
};
