<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * What a tracking link said at each point in its life. A click is recorded against the link, and the link can be edited
 * afterwards — its campaign, its UTMs, its destination — so without this a past click reads as though it had always carried
 * today's values. A revision is written when a link is created and whenever one of those values changes; rows are never
 * updated.
 *
 * Existing links get one revision holding their values as they stand today, marked `backfilled`: what they said before
 * this table existed was not recorded and is not reconstructed. Reversible: the table is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_link_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tracking_link_id')->constrained('tracking_links')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('snapshot');
            $table->jsonb('changed_fields');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('backfilled')->default(false);
            $table->timestampTz('effective_from');

            $table->unique(['tracking_link_id', 'revision']);
            $table->index(['workspace_id', 'tracking_link_id']);
        });

        DB::statement('ALTER TABLE tracking_link_revisions ADD CONSTRAINT tracking_link_revisions_revision_check CHECK (revision >= 1)');

        $fields = [
            'destination_url', 'platform', 'channel', 'external_account_id', 'campaign', 'ad_group', 'ad', 'creative',
            'campaign_id', 'ad_group_id', 'ad_id', 'creative_id', 'conversion_goal',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        ];

        DB::table('tracking_links')->orderBy('id')->chunk(500, function ($links) use ($fields): void {
            $rows = [];

            foreach ($links as $link) {
                $values = (array) $link;
                $snapshot = [];

                foreach ($fields as $field) {
                    $snapshot[$field] = $values[$field] ?? null;
                }

                $custom = json_decode((string) ($values['custom_params'] ?? '{}'), true);
                $snapshot['custom_params'] = is_array($custom) ? $custom : [];

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'workspace_id' => $values['workspace_id'],
                    'tracking_link_id' => $values['id'],
                    'revision' => 1,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'changed_fields' => '[]',
                    'changed_by' => null,
                    'backfilled' => true,
                    'effective_from' => $values['created_at'] ?? now(),
                ];
            }

            if ($rows !== []) {
                DB::table('tracking_link_revisions')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_link_revisions');
    }
};
