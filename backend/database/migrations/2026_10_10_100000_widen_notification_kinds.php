<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four more notification kinds, and the CHECK that has to agree with the enum.
 *
 * The constraint is generated from `NotificationKind::values()` rather than written out, which is the same rule
 * every enum in this schema follows: a list typed twice is a list that drifts, and the version that drifts is
 * always the one in the database — where a wrong value is already stored by the time anybody notices.
 *
 * Dropped and re-added rather than altered, because PostgreSQL has no `ALTER CONSTRAINT` for a check expression.
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
    }

    public function down(): void
    {
        /*
         * Rows carrying one of the new kinds are deleted rather than left to fail the narrower constraint.
         * A migration that rolls back into a state the data violates is a migration that cannot be rolled back,
         * and a notification is a record of having told somebody — losing one costs nothing it is worth keeping
         * a broken constraint for.
         */
        DB::table('notifications')->whereIn('kind', [
            NotificationKind::ClientRequestMoved->value,
            NotificationKind::ClientRequestNeedsInformation->value,
            NotificationKind::ClientRequestAnswered->value,
            NotificationKind::ClientRequestReplied->value,
        ])->delete();

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');

        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", [
                NotificationKind::TaskAssigned->value,
                NotificationKind::ApprovalRequested->value,
                NotificationKind::ApprovalDecided->value,
                NotificationKind::ApprovalEnacted->value,
                NotificationKind::ClientRequestSubmitted->value,
            ]),
        ));
    }
};
