<?php

declare(strict_types=1);

use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\DigestChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per notification per channel it was carried over: the `sent` and `failed` half of a notification's history.
 *
 * The bell itself is never a row here. `awaiting_credentials` is a state, written honestly while no provider is
 * connected; `sent` can only be written by an adapter holding a provider's acknowledgement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 24);
            $table->text('detail')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampsTz();

            $table->unique(['notification_id', 'channel']);
            $table->index(['workspace_id', 'user_id', 'status']);
        });

        DB::statement(sprintf("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check CHECK (channel IN ('%s'))", implode("','", array_values(array_diff(DigestChannel::values(), [DigestChannel::InApp->value])))));
        DB::statement(sprintf("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('%s'))", implode("','", DeliveryStatus::values())));
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
