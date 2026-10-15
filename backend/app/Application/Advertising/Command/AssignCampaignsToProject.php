<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Advertising\Exception\CampaignCannotJoinProject;

/**
 * Place many campaigns in one project — the bulk of the review queue in one decision.
 *
 * Each campaign goes through the single-campaign use case, so the rule that a campaign may only join a project
 * its account is bound to, and the audit entry per campaign, are the same ones — a bulk action is a loop over
 * the careful path, never a second path with its own shortcuts. Failure is per campaign: an account that is not
 * bound to the target refuses *that* campaign and says why, and the rest proceed.
 */
final readonly class AssignCampaignsToProject
{
    /** Bounded so one request is one screenful of review rather than an unattended migration. */
    public const int MAX = 100;

    public function __construct(private AssignCampaignToProject $assign) {}

    /**
     * @param  list<string>  $campaignIds
     * @return array{assigned: list<string>, refused: list<array{campaign_id: string, reason: string}>}
     */
    public function __invoke(array $campaignIds, string $projectId): array
    {
        $assigned = [];
        $refused = [];

        foreach (array_slice(array_values(array_unique($campaignIds)), 0, self::MAX) as $id) {
            try {
                ($this->assign)($id, $projectId);
                $assigned[] = $id;
            } catch (CampaignCannotJoinProject) {
                $refused[] = ['campaign_id' => $id, 'reason' => 'Its account is not bound to this project.'];
            } catch (ResourceNotFound) {
                /* Not visible to the actor is indistinguishable from absent, by design. */
                $refused[] = ['campaign_id' => $id, 'reason' => 'Campaign not found.'];
            }
        }

        return ['assigned' => $assigned, 'refused' => $refused];
    }
}
