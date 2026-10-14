<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Domain\Advertising\CampaignHealth;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;

/**
 * Campaigns the control center has already judged.
 *
 * Deliberately re-uses `GetCampaignControl` rather than reading metrics again. The verdict a campaign
 * carries here and the verdict on its own row must be the same verdict, and the only way to guarantee
 * that is for there to be one of them.
 */
final readonly class CampaignAttentionSource implements AttentionSource
{
    /** A queue is read top-down. Beyond this many campaigns the list has stopped being a to-do list. */
    private const int CAP = 25;

    public function __construct(private GetCampaignControl $campaigns) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Campaigns;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $page = ($this->campaigns)(
            new CampaignControlFilter(
                health: [CampaignHealth::Critical, CampaignHealth::NeedsAttention],
            ),
            self::CAP,
        );

        return array_map(
            fn (CampaignControlRow $row): AttentionItem => new AttentionItem(
                id: 'campaign:'.$row->campaignId,
                stream: AttentionStream::Campaigns,
                severity: $row->health === CampaignHealth::Critical
                    ? AttentionSeverity::Critical
                    : AttentionSeverity::NeedsAttention,
                /* The policy already wrote this sentence. Writing a second one here would be a second opinion. */
                message: $row->healthReason,
                entityKind: 'Campaign',
                entityLabel: $row->campaignName,
                /* A campaign is owned by whoever runs its project; the product does not assign campaigns. */
                owner: false,
                since: null,
                clientId: $row->clientId,
                clientName: $row->clientName,
                projectId: $row->projectId,
                projectName: $row->projectName,
            ),
            $page->rows,
        );
    }
}
