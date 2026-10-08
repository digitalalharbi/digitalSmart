<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A lead can now be put in somebody's name, so two CHECKs have to agree with two enums again.
 *
 * Both are generated from `values()` rather than written out — the rule every enum in this schema follows,
 * because a list typed twice drifts and the copy that drifts is always the one in the database, where a wrong
 * value is already stored by the time anybody notices.
 *
 * Dropped and re-added rather than altered: PostgreSQL has no `ALTER CONSTRAINT` for a check expression.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", NotificationKind::values()),
        ));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK '
            ."(subject_type IN ('%s'))",
            implode("','", NotificationSubject::values()),
        ));
    }

    public function down(): void
    {
        /*
         * Rows carrying the new kind go, rather than being left to fail the narrower constraint. A migration
         * that rolls back into a state the data violates cannot be rolled back, and a notification is a record
         * of having told somebody — losing one costs nothing worth keeping a broken constraint for.
         */
        DB::table('notifications')
            ->where('kind', NotificationKind::LeadAssigned->value)
            ->orWhere('subject_type', NotificationSubject::Lead->value)
            ->delete();

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", array_values(array_filter(
                NotificationKind::values(),
                static fn (string $kind): bool => $kind !== NotificationKind::LeadAssigned->value,
            ))),
        ));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK '
            ."(subject_type IN ('%s'))",
            implode("','", array_values(array_filter(
                NotificationSubject::values(),
                static fn (string $subject): bool => $subject !== NotificationSubject::Lead->value,
            ))),
        ));
    }
};
