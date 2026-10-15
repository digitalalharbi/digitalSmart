<?php

declare(strict_types=1);

namespace App\Domain\Leads;

/**
 * Whose leads, from the point of view of the person reading.
 *
 * A *narrowing*, never a widening: every scope is applied on top of what the reader is already permitted to
 * see, so "All" means all they may see and a media buyer choosing it does not become an administrator.
 */
enum LeadScope: string
{
    case All = 'all';
    /** Clients with at least one project the reader is a member of. */
    case MyClients = 'my_clients';
    /** Projects the reader is a member of. */
    case MyProjects = 'my_projects';
    /** Leads the reader owns. */
    case Mine = 'mine';
}
