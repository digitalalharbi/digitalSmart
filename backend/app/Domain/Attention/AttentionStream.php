<?php

declare(strict_types=1);

namespace App\Domain\Attention;

use App\Domain\Access\Permission;

/**
 * Where an item came from, and what it takes to see it.
 *
 * Each stream names its own permission, which is what makes the queue safe to union: a finance manager
 * and a creative lead open the same screen and the server assembles a different list, rather than one
 * list being filtered after the fact by an interface that could forget.
 *
 * The queue publishes which streams it actually consulted, because "nothing needs attention" means
 * something different to each of them and an empty list without its scope is a reassurance the product
 * has not earned.
 */
enum AttentionStream: string
{
    case Campaigns = 'campaigns';
    case Budgets = 'budgets';
    case Tracking = 'tracking';
    case Approvals = 'approvals';
    case Requests = 'requests';
    case Tasks = 'tasks';
    case Automation = 'automation';

    /**
     * Everything the reader needs before this stream is consulted. All of them, not any.
     *
     * A list rather than a single permission because of client requests: the agency queue requires
     * `client_requests.view`, and the query that reads it guards on `client_requests.submit` as well,
     * since the same query serves a client's own view of their own requests. Every system role that holds
     * one holds the other — but a custom role need not, and a stream that assumed otherwise would throw
     * a 403 out of a home screen instead of quietly leaving itself out.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Campaigns => [Permission::AnalyticsView],
            self::Budgets => [Permission::BudgetsView],
            self::Tracking => [Permission::TrackingView],
            self::Approvals => [Permission::ApprovalsView],
            self::Requests => [Permission::ClientRequestsView, Permission::ClientRequestsSubmit],
            self::Tasks => [Permission::TasksView],
            self::Automation => [Permission::AnalyticsView],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Campaigns => 'campaigns',
            self::Budgets => 'budgets',
            self::Tracking => 'tracking',
            self::Approvals => 'approvals',
            self::Requests => 'client requests',
            self::Tasks => 'tasks',
            self::Automation => 'rules',
        };
    }

    /** Where a reader is sent. The surface, not a deep link: each one opens on a selection. */
    public function path(): string
    {
        return match ($this) {
            self::Campaigns => '/campaigns',
            self::Budgets => '/budget-calculator',
            self::Tracking => '/tracking',
            self::Approvals => '/approvals',
            self::Requests => '/client-requests',
            self::Tasks => '/tasks',
            self::Automation => '/campaigns/rules',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
