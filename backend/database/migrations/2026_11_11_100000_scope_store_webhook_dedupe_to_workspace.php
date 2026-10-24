<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's webhook event id is unique **within one workspace's journal**, not across the installation.
 *
 * Two agencies may register the same shop, each with its own app and secret. With `(provider, external_event_id)` unique
 * across every workspace, the first tenant's delivery of an event made the second tenant's identical delivery an
 * `insertOrIgnore` no-op — read as a redelivery, so the second agency silently lost that order. Found by the tenancy
 * audit of every unique constraint that does not name its tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('store_webhook_events_unique');
            $table->unique(['workspace_id', 'provider', 'external_event_id'], 'store_webhook_events_unique');
        });
    }

    public function down(): void
    {
        /* The narrower key cannot hold two workspaces' copies of one event; keep the earliest and drop the rest. */
        DB::statement(<<<'SQL'
            DELETE FROM store_webhook_events e
            USING store_webhook_events keep
            WHERE e.provider = keep.provider
              AND e.external_event_id = keep.external_event_id
              AND e.external_event_id IS NOT NULL
              AND (e.created_at, e.id) > (keep.created_at, keep.id)
            SQL);

        Schema::table('store_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('store_webhook_events_unique');
            $table->unique(['provider', 'external_event_id'], 'store_webhook_events_unique');
        });
    }
};
