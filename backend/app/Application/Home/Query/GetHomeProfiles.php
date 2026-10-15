<?php

declare(strict_types=1);

namespace App\Application\Home\Query;

use App\Application\Shared\Contract\ActorContext;
use App\Domain\Access\Permission;
use App\Domain\Access\PermissionSet;

/**
 * Which home screens this person should see, decided from what they may *do* — never from a role's name.
 *
 * Rule 46: access is the combination of permissions and scope, and a custom role called "Head of Paid"
 * should get the media-buyer home because it holds the media buyer's permissions, not because somebody
 * remembered to add its name to a list. A person can match several — an owner who also buys media — and the
 * list is ordered so the first is the one to open on.
 *
 * It returns *which sections* each profile is made of, not their data. The data comes from the dedicated
 * read models those sections name, so a home screen can never show a figure the surface it links to does
 * not.
 */
final readonly class GetHomeProfiles
{
    /** @var array<string, array{label: string, sections: list<string>}> */
    private const array PROFILES = [
        'executive' => ['label' => 'Agency', 'sections' => ['portfolio', 'attention', 'client_ranking', 'budget_health', 'campaign_risk']],
        'project_manager' => ['label' => 'Projects', 'sections' => ['attention', 'my_projects', 'approvals', 'creative', 'reports_due']],
        'media_buyer' => ['label' => 'Campaigns', 'sections' => ['my_campaigns', 'attention', 'declining', 'tracking_issues', 'pacing']],
        'finance' => ['label' => 'Budgets', 'sections' => ['budget_control', 'overspend_risk', 'client_budget_ranking', 'platform_spend']],
        'creative' => ['label' => 'Creative', 'sections' => ['creative_pipeline', 'my_work', 'attention']],
    ];

    public function __construct(private ActorContext $actor) {}

    /** @return array{primary: ?string, profiles: list<array{key: string, label: string, sections: list<string>}>} */
    public function __invoke(): array
    {
        if (! $this->actor->isBound()) {
            return ['primary' => null, 'profiles' => []];
        }

        $matched = array_values(array_filter(
            array_keys(self::PROFILES),
            fn (string $key): bool => $this->matches($key, $this->actor->permissions()),
        ));

        $profiles = array_map(static fn (string $key): array => ['key' => $key] + self::PROFILES[$key], $matched);

        return ['primary' => $matched[0] ?? null, 'profiles' => $profiles];
    }

    private function matches(string $profile, PermissionSet $p): bool
    {
        return match ($profile) {
            'executive' => $p->hasAll(Permission::WorkspacesUpdate, Permission::BudgetsApprove, Permission::AnalyticsView),
            'project_manager' => $p->hasAll(Permission::ProjectsUpdate, Permission::ApprovalsDecide),
            /*
             * What separates buying media from managing projects is *deciding*: the buyer reads performance
             * and tracking and asks for changes; the manager is the one who approves them.
             */
            'media_buyer' => $p->hasAll(Permission::AnalyticsView, Permission::TrackingView) && ! $p->has(Permission::ApprovalsDecide),
            'finance' => $p->has(Permission::BudgetsApprove) && ! $p->has(Permission::WorkspacesUpdate),
            'creative' => $p->has(Permission::CreativeManage) && ! $p->has(Permission::AnalyticsView),
            default => false,
        };
    }
}
