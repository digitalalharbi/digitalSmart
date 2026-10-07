<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What a task is *about*.
 *
 * A link, never a claim. Attaching a task to a creative request says somebody has work to do concerning
 * it; it does not say the request changed, and closing the task changes nothing about the subject. The
 * two have separate state machines on purpose — a board that advanced a brief because somebody ticked a
 * checklist item would produce an approval trail nobody could rely on.
 *
 * There is **no foreign key** behind this, which is a deliberate trade and not an oversight. A
 * polymorphic foreign key cannot be expressed in one column, and the alternative — five nullable keys —
 * would make the common read a five-way join for a label. The cost is that a subject can be deleted
 * underneath a task, so the read model resolves the subject every time and reports a missing one as
 * missing rather than rendering a dead link.
 */
enum TaskSubjectType: string
{
    case CreativeRequest = 'creative_request';

    case Lead = 'lead';

    case Campaign = 'campaign';

    case Budget = 'budget';

    case Project = 'project';

    /** Work a client asked for. The link that keeps an intake queue from becoming a second task board. */
    case ClientRequest = 'client_request';

    /** The table the subject's label is read from. */
    public function table(): string
    {
        return match ($this) {
            self::CreativeRequest => 'creative_requests',
            self::Lead => 'leads',
            self::Campaign => 'campaigns',
            self::Budget => 'budgets',
            self::Project => 'projects',
            self::ClientRequest => 'client_requests',
        };
    }

    /**
     * The column that holds something worth showing a person.
     *
     * A lead has no title, so it is named by the person who enquired. Where even that is absent the read
     * model falls back to saying the subject exists without a name, rather than printing an identifier.
     */
    public function labelColumn(): string
    {
        return match ($this) {
            // A brief and a client's request are called titles; everything else here is called a name.
            self::CreativeRequest, self::ClientRequest => 'title',
            self::Budget, self::Project, self::Campaign, self::Lead => 'name',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CreativeRequest => 'Creative request',
            self::Lead => 'Lead',
            self::Campaign => 'Campaign',
            self::Budget => 'Budget',
            self::Project => 'Project',
            self::ClientRequest => 'Client request',
        };
    }

    /** The surface a reader should be sent to, relative to the application root. */
    public function path(string $id): string
    {
        return match ($this) {
            self::CreativeRequest => '/creative-hub',
            self::Lead => '/leads',
            self::Campaign => '/campaigns',
            self::Budget => '/media-planner',
            self::Project => '/projects/'.$id,
            self::ClientRequest => '/client-requests',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
