<?php

declare(strict_types=1);

use App\Domain\Attention\DigestKind;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A notification says how much it matters, which tab it is under, what it rests on, and whether it is a repeat.
 *
 * `severity` and `category` are stored, not derived on read, because a tab is a WHERE clause and a badge is a
 * grouped count — both of which have to agree with the list below them. `payload` carries the figures an alert
 * or digest card draws (a value, a change, a currency), never a sentence. `dedupe_key` is what lets "ROAS low"
 * be said once per cooldown rather than every hour. `clicked_at` and `dismissed_at` complete the history a
 * notification has: generated (created_at), read, clicked, dismissed.
 *
 * Every vocabulary CHECK is rebuilt from its enum, as the earlier widenings were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('severity', 16)->default(NotificationSeverity::Info->value);
            $table->string('category', 24)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->timestampTz('clicked_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();

            $table->index(['workspace_id', 'dedupe_key', 'created_at'], 'notifications_dedupe_idx');
            $table->index(['workspace_id', 'user_id', 'category', 'read_at'], 'notifications_category_idx');
        });

        /* Existing rows take the category their kind now declares; the enum is the one list. */
        foreach (NotificationKind::cases() as $kind) {
            DB::table('notifications')->where('kind', $kind->value)->update(['category' => $kind->category()->value]);
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN category SET NOT NULL');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_severity_check CHECK (severity IN ('%s'))", implode("','", NotificationSeverity::values())));
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_category_check CHECK (category IN ('%s'))", implode("','", NotificationCategory::values())));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))", implode("','", NotificationKind::values())));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))", implode("','", NotificationSubject::values())));

        DB::statement('ALTER TABLE digests DROP CONSTRAINT digests_kind_check');
        DB::statement(sprintf("ALTER TABLE digests ADD CONSTRAINT digests_kind_check CHECK (kind IN ('%s'))", implode("','", DigestKind::values())));
    }

    public function down(): void
    {
        $newKinds = [
            NotificationKind::PerformanceDigest, NotificationKind::RoasDrop, NotificationKind::CostPerResultRise,
            NotificationKind::SpendSpike, NotificationKind::BudgetNearLimit, NotificationKind::NoResults,
            NotificationKind::TrackingFailure, NotificationKind::CampaignRejected, NotificationKind::CreativeFatigue,
            NotificationKind::CampaignEnded, NotificationKind::CampaignUnderDelivering,
        ];
        $newSubjects = [NotificationSubject::Campaign, NotificationSubject::Budget, NotificationSubject::Creative, NotificationSubject::MeasurementSource];
        $newDigests = [DigestKind::DailyPerformance, DigestKind::WeeklyPerformance, DigestKind::MonthlyPerformance];
        $values = static fn (array $cases): array => array_map(static fn ($case): string => $case->value, $cases);

        DB::table('notifications')->whereIn('kind', $values($newKinds))->orWhereIn('subject_type', $values($newSubjects))->delete();
        DB::table('digests')->whereIn('kind', $values($newDigests))->delete();

        DB::statement('ALTER TABLE digests DROP CONSTRAINT digests_kind_check');
        DB::statement(sprintf("ALTER TABLE digests ADD CONSTRAINT digests_kind_check CHECK (kind IN ('%s'))", implode("','", array_values(array_diff(DigestKind::values(), $values($newDigests))))));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('%s'))", implode("','", array_values(array_diff(NotificationKind::values(), $values($newKinds))))));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_subject_type_check');
        DB::statement(sprintf("ALTER TABLE notifications ADD CONSTRAINT notifications_subject_type_check CHECK (subject_type IN ('%s'))", implode("','", array_values(array_diff(NotificationSubject::values(), $values($newSubjects))))));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_severity_check');
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_category_check');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_dedupe_idx');
            $table->dropIndex('notifications_category_idx');
            $table->dropColumn(['severity', 'category', 'payload', 'dedupe_key', 'clicked_at', 'dismissed_at']);
        });
    }
};
