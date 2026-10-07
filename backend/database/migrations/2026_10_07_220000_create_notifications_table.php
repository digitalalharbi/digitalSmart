<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications: one row per person per thing they were told.
 *
 * Deliberately **not** Laravel's own `notifications` table. That one is polymorphic over a notifiable, carries
 * its payload as untyped JSON and has no workspace column — and every one of those is a property this product
 * needs the opposite of: a notification here is scoped to a workspace, carries a closed vocabulary enforced by
 * a CHECK, and is read back with a per-person unread count that has to be correct.
 *
 * Four rules in the schema:
 *
 *  - **One row per recipient.** Fan-out happens at write time rather than at read time. A single row with a
 *    list of recipients cannot record that one of them has read it, which is the only state that matters.
 *  - **The sentence is stored as it was announced.** `title` and `body` are a record of what was said, not a
 *    template rendered later: a notification whose wording changes when the underlying record changes is not a
 *    record of anything.
 *  - **The subject is a pointer with no foreign key**, the same trade `TaskSubjectType` explains. It is
 *    resolved on every read, and a subject that has been deleted is reported as deleted.
 *  - **`read_at` is nullable and nothing else marks read state.** No boolean beside it to disagree with.
 *
 * There is no `actor_id` constraint saying the actor is not the recipient, because the rule belongs where it
 * can be stated with its reason — `Announce` drops the actor before writing. A CHECK could enforce it, but it
 * would also make a legitimate future case (telling somebody what they did on another device) a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            /** The recipient. One row each — see the note above. */
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            /** Who caused it, for the sentence. Nullable: a future system event has no person behind it. */
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind');
            $table->string('title');
            $table->text('body')->nullable();

            $table->string('subject_type');
            $table->uuid('subject_id');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /*
             * The index the unread count uses. `(workspace, user, read_at)` rather than `(user, read_at)`,
             * because every read in this product is workspace-scoped first and a person may be in several.
             */
            $table->index(['workspace_id', 'user_id', 'read_at']);
            $table->index(['workspace_id', 'subject_type', 'subject_id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", NotificationKind::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))",
            implode("','", NotificationSubject::values()),
        ));

        DB::statement(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_title_present CHECK (btrim(title) <> '')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
