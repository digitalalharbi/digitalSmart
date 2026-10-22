<?php

declare(strict_types=1);

namespace App\Domain\SurfaceView;

use App\Domain\Access\Permission;

/**
 * An agency surface whose filter state can be saved.
 *
 * Each surface names the permission it is read under and the filters it understands. Anything else in a saved
 * payload is dropped on write, so a view cannot smuggle a parameter the surface never offered — and a surface
 * that later retires a filter does not have to migrate old views to stay safe.
 */
enum SurfaceKind: string
{
    case CampaignControl = 'campaign_control';
    case BudgetControl = 'budget_control';
    case Leads = 'leads';
    case AdGroups = 'ad_groups';

    public function permission(): Permission
    {
        return match ($this) {
            self::CampaignControl, self::BudgetControl, self::AdGroups => Permission::AnalyticsView,
            self::Leads => Permission::LeadsView,
        };
    }

    /** @return list<string> */
    public function filters(): array
    {
        return match ($this) {
            /* The names the page's own URL uses, so a saved view *is* a link and opens by setting them. */
            self::CampaignControl => ['client', 'project', 'account', 'tag', 'mine', 'days', 'provider', 'status', 'objective', 'health', 'ends', 'budget', 'zero', 'q', 'sort', 'dir', 'view'],
            self::BudgetControl => ['risk', 'client', 'project', 'q'],
            self::AdGroups => ['client', 'project', 'campaign', 'mine', 'days', 'provider', 'status', 'zero', 'q', 'sort', 'dir'],
            self::Leads => ['scope', 'client_id', 'project_id', 'owner_id', 'source_kind', 'campaign', 'days'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
