<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A digest can now be announced, so the two enum-backed CHECKs are re-derived from the enums — never typed
 * out, because a list written twice drifts and the stale copy is the one in the database.
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
            "ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))",
            implode("','", NotificationSubject::values()),
        ));
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('kind', NotificationKind::DigestReady->value)
            ->orWhere('subject_type', NotificationSubject::Digest->value)
            ->delete();

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))",
            implode("','", array_values(array_filter(NotificationKind::values(), static fn (string $k): bool => $k !== NotificationKind::DigestReady->value))),
        ));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf(
            "ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))",
            implode("','", array_values(array_filter(NotificationSubject::values(), static fn (string $s): bool => $s !== NotificationSubject::Digest->value))),
        ));
    }
};
