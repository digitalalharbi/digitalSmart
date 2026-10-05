<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Advertising\Contract\AdvertisingStore;
use App\Application\Advertising\DTO\SyncContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\AdEntityType;
use App\Infrastructure\Persistence\Eloquent\Model\Ad;
use App\Infrastructure\Persistence\Eloquent\Model\AdGroup;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\Creative;
use App\Infrastructure\Persistence\Eloquent\Model\CreativeAsset;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes synced advertising data.
 *
 * Idempotency lives here and in the unique indexes beneath it: every write is
 * keyed on the provider's own identifier, so a retried job, an overlapping date
 * range or a replayed page updates a row instead of adding one. The alternative
 * is double-counted spend, which is a number a client would be billed against.
 */
final class EloquentAdvertisingStore implements AdvertisingStore
{
    /**
     * Provider id → our id, and our id → our parent's id, per account.
     *
     * Memoised for the lifetime of one sync. Without it every page of ads
     * re-reads the whole campaign, ad-group and creative tables, and a page of
     * insights re-reads six maps — which turns a wide date range into thousands
     * of identical queries. Structure writes invalidate the maps they affect, so
     * a child page still sees the parents the previous page created.
     *
     * @var array<string, array<string, string>>
     */
    private array $maps = [];

    public function __construct(private readonly Clock $clock) {}

    public function upsertCampaigns(SyncContext $context, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        return DB::transaction(function () use ($context, $records): int {
            $now = $this->clock->now();
            $written = 0;

            foreach ($records as $record) {
                Campaign::query()->updateOrCreate(
                    [
                        'external_account_id' => $context->externalAccountId,
                        'external_id' => $record->externalId,
                    ],
                    [
                        'workspace_id' => $context->workspaceId,
                        'provider' => $context->provider->value,
                        'name' => $record->name,
                        'objective' => $record->objective,
                        'raw_objective' => $record->rawObjective,
                        'status' => $record->status,
                        'raw_status' => $record->rawStatus,
                        'raw_effective_status' => $record->rawEffectiveStatus,
                        'buying_type' => $record->buyingType,
                        'bid_strategy' => $record->bidStrategy,
                        'budget_mode' => $record->budgetMode,
                        'budget_minor' => $record->budgetMinor,
                        'spend_cap_minor' => $record->spendCapMinor,
                        'currency' => $record->currency ?? $context->currency,
                        'starts_at' => $record->startsAt,
                        'ends_at' => $record->endsAt,
                        'provider_created_at' => $record->providerCreatedAt,
                        'provider_updated_at' => $record->providerUpdatedAt,
                        'metadata' => $record->metadata,
                        'synced_at' => $now,
                    ],
                );

                $written++;
            }

            $this->forget(Campaign::class);

            return $written;
        });
    }

    public function upsertAdGroups(SyncContext $context, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        return DB::transaction(function () use ($context, $records): int {
            $campaigns = $this->idMap(Campaign::class, $context->externalAccountId);
            $now = $this->clock->now();
            $written = 0;

            foreach ($records as $record) {
                $campaignId = $campaigns[$record->campaignExternalId] ?? null;

                if ($campaignId === null) {
                    /*
                     * Its campaign has not been synced. Skipped rather than
                     * stubbed: a placeholder campaign would appear in every
                     * filter and report as a real one with no name.
                     */
                    continue;
                }

                AdGroup::query()->updateOrCreate(
                    [
                        'external_account_id' => $context->externalAccountId,
                        'external_id' => $record->externalId,
                    ],
                    [
                        'workspace_id' => $context->workspaceId,
                        'campaign_id' => $campaignId,
                        'provider' => $context->provider->value,
                        'name' => $record->name,
                        'status' => $record->status,
                        'raw_status' => $record->rawStatus,
                        'raw_effective_status' => $record->rawEffectiveStatus,
                        'optimization_goal' => $record->optimizationGoal,
                        'billing_event' => $record->billingEvent,
                        'bid_amount_minor' => $record->bidAmountMinor,
                        'budget_mode' => $record->budgetMode,
                        'budget_minor' => $record->budgetMinor,
                        'currency' => $record->currency ?? $context->currency,
                        'starts_at' => $record->startsAt,
                        'ends_at' => $record->endsAt,
                        'provider_created_at' => $record->providerCreatedAt,
                        'provider_updated_at' => $record->providerUpdatedAt,
                        'targeting' => $record->targeting,
                        'metadata' => $record->metadata,
                        'synced_at' => $now,
                    ],
                );

                $written++;
            }

            $this->forget(AdGroup::class);

            return $written;
        });
    }

    public function upsertCreatives(SyncContext $context, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        return DB::transaction(function () use ($context, $records): int {
            $now = $this->clock->now();
            $written = 0;

            foreach ($records as $record) {
                $creative = Creative::query()->updateOrCreate(
                    [
                        'external_account_id' => $context->externalAccountId,
                        'external_id' => $record->externalId,
                    ],
                    [
                        'workspace_id' => $context->workspaceId,
                        'provider' => $context->provider->value,
                        'name' => $record->name,
                        'format' => $record->format,
                        'title' => $record->title,
                        'body' => $record->body,
                        'call_to_action' => $record->callToAction,
                        'destination_url' => $record->destinationUrl,
                        'thumbnail_url' => $record->thumbnailUrl,
                        'preview_url' => $record->previewUrl,
                        'metadata' => $record->metadata,
                        'synced_at' => $now,
                    ],
                );

                /*
                 * Assets are replaced wholesale rather than merged. A carousel
                 * whose third card was removed must lose that card, and matching
                 * on position would keep a stale one; the provider's asset list
                 * is the whole truth about the creative.
                 */
                CreativeAsset::query()->where('creative_id', $creative->getKey())->delete();

                foreach ($record->assets as $asset) {
                    CreativeAsset::query()->create([
                        'creative_id' => $creative->getKey(),
                        'kind' => $asset->kind,
                        'url' => $asset->url,
                        'external_id' => $asset->externalId,
                        'width' => $asset->width,
                        'height' => $asset->height,
                        'duration_ms' => $asset->durationMs,
                        'file_size_bytes' => $asset->fileSizeBytes,
                        'mime_type' => $asset->mimeType,
                        'position' => $asset->position,
                        'metadata' => $asset->metadata,
                    ]);
                }

                $written++;
            }

            $this->forget(Creative::class);

            return $written;
        });
    }

    public function upsertAds(SyncContext $context, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        return DB::transaction(function () use ($context, $records): int {
            $campaigns = $this->idMap(Campaign::class, $context->externalAccountId);
            $adGroups = $this->idMap(AdGroup::class, $context->externalAccountId);
            $creatives = $this->idMap(Creative::class, $context->externalAccountId);
            $now = $this->clock->now();
            $written = 0;

            foreach ($records as $record) {
                $adGroupId = $adGroups[$record->adGroupExternalId] ?? null;
                $campaignId = $campaigns[$record->campaignExternalId] ?? null;

                if ($adGroupId === null || $campaignId === null) {
                    continue;
                }

                Ad::query()->updateOrCreate(
                    [
                        'external_account_id' => $context->externalAccountId,
                        'external_id' => $record->externalId,
                    ],
                    [
                        'workspace_id' => $context->workspaceId,
                        'campaign_id' => $campaignId,
                        'ad_group_id' => $adGroupId,
                        // Null when the creative has not been synced yet. The ad
                        // is still worth keeping; the link fills in next run.
                        'creative_id' => $record->creativeExternalId === null
                            ? null
                            : ($creatives[$record->creativeExternalId] ?? null),
                        'provider' => $context->provider->value,
                        'name' => $record->name,
                        'status' => $record->status,
                        'raw_status' => $record->rawStatus,
                        'raw_effective_status' => $record->rawEffectiveStatus,
                        'review_feedback' => $record->reviewFeedback,
                        'provider_created_at' => $record->providerCreatedAt,
                        'provider_updated_at' => $record->providerUpdatedAt,
                        'metadata' => $record->metadata,
                        'synced_at' => $now,
                    ],
                );

                $written++;
            }

            $this->forget(Ad::class);

            return $written;
        });
    }

    public function upsertInsights(SyncContext $context, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        return DB::transaction(function () use ($context, $records): int {
            $campaigns = $this->idMap(Campaign::class, $context->externalAccountId);
            $adGroups = $this->idMap(AdGroup::class, $context->externalAccountId);
            $ads = $this->idMap(Ad::class, $context->externalAccountId);
            $adGroupParents = $this->parentMap(AdGroup::class, $context->externalAccountId, 'campaign_id');
            $adParents = $this->parentMap(Ad::class, $context->externalAccountId, 'ad_group_id');
            $adCampaigns = $this->parentMap(Ad::class, $context->externalAccountId, 'campaign_id');

            $now = $this->clock->now()->format(DateTimeInterface::ATOM);
            $metricRows = [];
            $conversionRows = [];

            foreach ($records as $record) {
                $entityId = match ($record->entityType) {
                    AdEntityType::Campaign => $campaigns[$record->externalEntityId] ?? null,
                    AdEntityType::AdGroup => $adGroups[$record->externalEntityId] ?? null,
                    AdEntityType::Ad => $ads[$record->externalEntityId] ?? null,
                    // Account-level rows attach to the external account itself.
                    AdEntityType::Account => $context->externalAccountId,
                };

                if ($entityId === null) {
                    /*
                     * Performance for something we have not synced. Dropped, not
                     * invented: a metric row with no entity is a row no report
                     * can label, and an unlabelled number is worse than a gap.
                     */
                    continue;
                }

                // Denormalised ancestry, so the common filters join nothing.
                [$campaignId, $adGroupId, $adId] = match ($record->entityType) {
                    AdEntityType::Campaign => [$entityId, null, null],
                    AdEntityType::AdGroup => [$adGroupParents[$entityId] ?? null, $entityId, null],
                    AdEntityType::Ad => [$adCampaigns[$entityId] ?? null, $adParents[$entityId] ?? null, $entityId],
                    AdEntityType::Account => [null, null, null],
                };

                $date = $record->date->format('Y-m-d');

                $metricRows[] = [
                    'id' => (string) Str::uuid7(),
                    'workspace_id' => $context->workspaceId,
                    'external_account_id' => $context->externalAccountId,
                    'provider' => $context->provider->value,
                    'entity_type' => $record->entityType->value,
                    'entity_id' => $entityId,
                    'external_entity_id' => $record->externalEntityId,
                    'campaign_id' => $campaignId,
                    'ad_group_id' => $adGroupId,
                    'ad_id' => $adId,
                    'date' => $date,
                    'account_timezone' => $record->accountTimezone ?? $context->accountTimezone,
                    'currency' => $record->currency,
                    'spend_minor' => $record->spendMinor,
                    'revenue_minor' => $record->revenueMinor,
                    'impressions' => $record->impressions,
                    'reach' => $record->reach,
                    'clicks' => $record->clicks,
                    'link_clicks' => $record->linkClicks,
                    'video_views' => $record->videoViews,
                    'video_completions' => $record->videoCompletions,
                    'engagements' => $record->engagements,
                    'conversions' => $record->conversions,
                    'source' => $context->source->value,
                    'metadata' => json_encode($record->metadata),
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($record->conversionBreakdown as $conversion) {
                    $conversionRows[] = [
                        'id' => (string) Str::uuid7(),
                        'workspace_id' => $context->workspaceId,
                        'external_account_id' => $context->externalAccountId,
                        'provider' => $context->provider->value,
                        'entity_type' => $record->entityType->value,
                        'entity_id' => $entityId,
                        'campaign_id' => $campaignId,
                        'date' => $date,
                        'action_type' => $conversion->actionType->value,
                        'raw_action_type' => $conversion->rawActionType,
                        'action_name' => $conversion->actionName,
                        'count' => $conversion->count,
                        'value_minor' => $conversion->valueMinor,
                        'currency' => $conversion->currency,
                        // See the migration: a null here would break idempotency,
                        // because PostgreSQL treats nulls as distinct in a
                        // unique index. 'default' is the account's own window.
                        'attribution_window' => $conversion->attributionWindow ?? 'default',
                        'source' => $context->source->value,
                        'metadata' => json_encode($conversion->metadata),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            /*
             * Batched rather than row-by-row. This is the largest table in the
             * system and a wide date range is thousands of rows per level; one
             * statement per chunk keeps a sync inside a sensible transaction
             * instead of holding one open across thousands of round trips.
             */
            $this->upsertChunks(
                'daily_metrics',
                $metricRows,
                ['entity_type', 'entity_id', 'date'],
                [
                    'workspace_id', 'external_account_id', 'provider', 'external_entity_id',
                    'campaign_id', 'ad_group_id', 'ad_id', 'account_timezone', 'currency',
                    'spend_minor', 'revenue_minor', 'impressions', 'reach', 'clicks',
                    'link_clicks', 'video_views', 'video_completions', 'engagements',
                    'conversions', 'source', 'metadata', 'synced_at', 'updated_at',
                ],
            );

            $this->upsertChunks(
                'conversion_metrics',
                $conversionRows,
                ['entity_type', 'entity_id', 'date', 'raw_action_type', 'attribution_window'],
                [
                    'workspace_id', 'external_account_id', 'provider', 'campaign_id',
                    'action_type', 'action_name', 'count', 'value_minor', 'currency',
                    'source', 'metadata', 'updated_at',
                ],
            );

            return count($metricRows);
        });
    }

    /**
     * Upsert in chunks, keyed on a unique index.
     *
     * `id` is deliberately absent from the update list: a row that already
     * exists keeps the identifier anything else may already reference.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    private function upsertChunks(string $table, array $rows, array $uniqueBy, array $update): void
    {
        if ($rows === []) {
            return;
        }

        /*
         * PostgreSQL binds one parameter per column per row and tops out at
         * 65,535, so the chunk size is derived from the row width rather than
         * guessed — a wider table gets smaller chunks automatically.
         */
        $columns = max(1, count($rows[0]));
        $perChunk = max(1, (int) floor(60000 / $columns));

        foreach (array_chunk($rows, $perChunk) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $update);
        }
    }

    /**
     * Provider id → our id, for one account.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return array<string, string>
     */
    private function idMap(string $model, string $externalAccountId): array
    {
        return $this->lookup($model, $externalAccountId, 'external_id', 'id');
    }

    /**
     * Our id → our parent's id, for one account.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return array<string, string>
     */
    private function parentMap(string $model, string $externalAccountId, string $column): array
    {
        return $this->lookup($model, $externalAccountId, 'id', $column);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return array<string, string>
     */
    private function lookup(string $model, string $externalAccountId, string $keyColumn, string $valueColumn): array
    {
        $cacheKey = $model.'|'.$externalAccountId.'|'.$keyColumn.'>'.$valueColumn;

        if (isset($this->maps[$cacheKey])) {
            return $this->maps[$cacheKey];
        }

        /** @var array<string, string> $map */
        $map = $model::query()
            ->where('external_account_id', $externalAccountId)
            ->pluck($valueColumn, $keyColumn)
            ->all();

        return $this->maps[$cacheKey] = $map;
    }

    /**
     * Drop the memoised maps for one model.
     *
     * Called after writing that model, so the next page sees what this one
     * created rather than a snapshot taken before it existed.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function forget(string $model): void
    {
        foreach (array_keys($this->maps) as $key) {
            if (str_starts_with($key, $model.'|')) {
                unset($this->maps[$key]);
            }
        }
    }
}
