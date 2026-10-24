<?php

declare(strict_types=1);

namespace App\Domain\Saas;

/**
 * Something measurable a plan bounds.
 *
 * Counts are **derived** from the rows that exist (clients, projects, members, connections, accounts); monthly figures are
 * **recorded** once per event, idempotently, in usage meters. Nothing is estimated.
 */
enum Limit: string
{
    case InternalUsers = 'internal_users';
    case ClientUsers = 'client_users';
    case Clients = 'clients';
    case Projects = 'projects';
    case ProviderConnections = 'provider_connections';
    case ExternalAccounts = 'external_accounts';
    case TrackingEventsMonthly = 'tracking_events_monthly';
    case FormSubmissionsMonthly = 'form_submissions_monthly';
    case AutomationRunsMonthly = 'automation_runs_monthly';
    case AiRequestsMonthly = 'ai_requests_monthly';
    case ExportsMonthly = 'exports_monthly';
    case StorageBytes = 'storage_bytes';
    case CampaignLaunchesMonthly = 'campaign_launches_monthly';

    /** Counted per calendar month (UTC) and reset at its start. */
    public function isMonthly(): bool
    {
        return str_ends_with($this->value, '_monthly');
    }

    /**
     * Whether reaching the limit refuses the next action.
     *
     * A visitor's form submission or page event is never refused for the workspace's quota — that would lose a lead somebody
     * paid for — so those are **recorded and shown as over the limit** instead. Everything a member of the workspace does is
     * refused with the reason.
     */
    public function refusesWhenExhausted(): bool
    {
        return ! in_array($this, [self::TrackingEventsMonthly, self::FormSubmissionsMonthly], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
