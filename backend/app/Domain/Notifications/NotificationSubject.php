<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What a notification points at.
 *
 * A notification is a **pointer with a sentence on it**, never a copy of the thing. The sentence is the record
 * of what was announced and does not change afterwards; the subject is resolved on every read, so a
 * notification about something that has since been deleted says so rather than offering a link that goes
 * nowhere. Two tables, no foreign key, the same trade `TaskSubjectType` explains.
 */
enum NotificationSubject: string
{
    case Task = 'task';

    case Approval = 'approval';

    case ClientRequest = 'client_request';

    case Lead = 'lead';

    case Digest = 'digest';

    case Export = 'export';

    case Campaign = 'campaign';

    case Budget = 'budget';

    case Creative = 'creative';

    case MeasurementSource = 'measurement_source';

    case AccessRequest = 'access_request';

    public function table(): string
    {
        return match ($this) {
            self::Task => 'tasks',
            self::Approval => 'approval_requests',
            self::ClientRequest => 'client_requests',
            self::Lead => 'leads',
            self::Digest => 'digests',
            self::Export => 'export_jobs',
            self::Campaign => 'campaigns',
            self::Budget => 'budgets',
            self::Creative => 'creatives',
            self::MeasurementSource => 'measurement_sources',
            self::AccessRequest => 'access_requests',
        };
    }

    /**
     * The column holding something a reader would recognise.
     *
     * A match rather than a constant, because a lead has no title: it is a person, and the nearest thing to a
     * title is their name — which is nullable, so a notification about an anonymous enquiry resolves to no label
     * and the surface says so rather than printing an empty string.
     */
    public function labelColumn(): string
    {
        return match ($this) {
            self::Task, self::Approval, self::ClientRequest => 'title',
            self::Lead => 'name',
            self::Digest => 'kind',
            self::Export => 'filename',
            self::Campaign, self::Budget, self::Creative, self::MeasurementSource => 'name',
            /* Not the person's name: the label is shown in a bell, and the bell is not where a stranger's details belong. */
            self::AccessRequest => 'kind',
        };
    }

    /** Where a reader should be sent. The surface, not a deep link: both surfaces open on a selection. */
    public function path(): string
    {
        return match ($this) {
            self::Task => '/tasks',
            self::Approval => '/approvals',
            self::ClientRequest => '/client-requests',
            self::Lead => '/leads',
            self::Digest => '/',
            self::Export => '/reports',
            self::Campaign => '/campaigns',
            self::Budget => '/budget-control',
            self::Creative => '/creatives',
            self::MeasurementSource => '/tracking',
            self::AccessRequest => '/access-requests',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Task',
            self::Approval => 'Approval request',
            self::ClientRequest => 'Client request',
            self::Lead => 'Lead',
            self::Digest => 'Digest',
            self::Export => 'Export',
            self::Campaign => 'Campaign',
            self::Budget => 'Budget',
            self::Creative => 'Creative',
            self::MeasurementSource => 'Measurement source',
            self::AccessRequest => 'Request',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
