<?php

declare(strict_types=1);

use App\Domain\Notifications\DeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quiet hours and a snooze, per person per workspace.
 *
 * `quiet_from` / `quiet_until` are local hours in the person's zone (or the workspace's), both set or both null, and may
 * wrap midnight. `snoozed_until` is an instant. Neither stops a notification being written to the bell — they stop it
 * interrupting: no realtime push, no email or WhatsApp copy. A copy not sent for that reason is a `held` delivery row,
 * which is why the status vocabulary widens here. Nothing held is sent later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('quiet_from')->nullable();
            $table->unsignedSmallInteger('quiet_until')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
        });

        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_quiet_check CHECK ((quiet_from IS NULL AND quiet_until IS NULL) OR (quiet_from BETWEEN 0 AND 23 AND quiet_until BETWEEN 0 AND 23 AND quiet_from <> quiet_until))');

        $this->statuses(DeliveryStatus::values());
    }

    public function down(): void
    {
        DB::table('notification_deliveries')->where('status', DeliveryStatus::Held->value)->delete();
        $this->statuses(array_values(array_diff(DeliveryStatus::values(), [DeliveryStatus::Held->value])));

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_quiet_check');
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['quiet_from', 'quiet_until', 'snoozed_until']);
        });
    }

    /** @param list<string> $values */
    private function statuses(array $values): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_status_check');
        DB::statement(sprintf("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('%s'))", implode("','", $values)));
    }
};
