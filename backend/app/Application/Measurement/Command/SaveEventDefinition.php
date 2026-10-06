<?php

declare(strict_types=1);

namespace App\Application\Measurement\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\DTO\EventDefinitionInput;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\EventMappingView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Measurement\Exception\InvalidEventDefinition;

/**
 * Define what a project counts, and which raw event names mean it.
 *
 * This is the hinge of the measurement layer. Nothing in this product infers meaning from an
 * event name — one property's `contact` is a form submission and another's is a phone tap —
 * so a raw name counts towards a conversion **only** because somebody mapped it here, and
 * that mapping is a record with an author and an audit entry.
 *
 * Three rules, each preventing a number nobody could defend:
 *
 *  - **A definition with no mappings is refused.** Nothing would ever count towards it, and a
 *    conversion permanently reading zero is worse than no conversion: it looks like failure
 *    rather than absence.
 *  - **A raw name may mean one thing per project.** Two definitions claiming `purchase` both
 *    count every purchase, so a dashboard showing both shows the same sale twice.
 *  - **Only a conversion can be primary.** "The most important thing we are not counting as a
 *    conversion" is not a sentence anybody means.
 */
final readonly class SaveEventDefinition
{
    public function __construct(
        private EventDefinitionRepository $definitions,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(EventDefinitionInput $input, ?string $id = null): EventDefinitionView
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($input->isPrimary && ! $input->isConversion) {
            throw InvalidEventDefinition::primaryMustBeAConversion();
        }

        if ($input->mappings === []) {
            throw InvalidEventDefinition::needsAtLeastOneMapping($input->name);
        }

        $this->assertNamesAreFree($workspaceId, $input, $id);

        if ($id === null) {
            $definition = $this->definitions->create($workspaceId, $this->actor->userId()->value, $input);
            $action = AuditAction::ConversionDefined;
            $changes = $this->snapshot($definition);
        } else {
            $existing = $this->definitions->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

            if ($existing === null) {
                throw ResourceNotFound::of('Conversion definition');
            }

            $definition = $this->definitions->update($workspaceId, $id, $input);
            $action = AuditAction::ConversionChanged;
            /*
             * Before and after, because changing what a conversion counts changes every report
             * that has ever been read from it. "Leads halved in March" is sometimes a campaign
             * and sometimes a mapping somebody edited, and only this says which.
             */
            $changes = ['before' => $this->snapshot($existing), 'after' => $this->snapshot($definition)];
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'event_definition',
            auditableId: $definition->id,
            summary: sprintf(
                '%s conversion "%s" (%s)',
                $id === null ? 'Defined' : 'Changed',
                $definition->name,
                $definition->normalizedEvent->value,
            ),
            changes: $changes,
        ));

        return $definition;
    }

    /**
     * No raw name may mean two things on one project.
     *
     * Checked against every other definition in the same scope rather than only within this
     * one, because the double-count a reader sees does not care which record caused it.
     *
     * @throws InvalidEventDefinition
     */
    private function assertNamesAreFree(string $workspaceId, EventDefinitionInput $input, ?string $id): void
    {
        $taken = [];

        $siblings = $this->definitions->visibleTo(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $input->clientId,
            $input->projectId,
        );

        foreach ($siblings as $sibling) {
            if ($sibling->id === $id) {
                continue;
            }

            if ($sibling->name === $input->name) {
                throw InvalidEventDefinition::nameAlreadyUsed($input->name);
            }

            foreach ($sibling->mappings as $mapping) {
                $taken[$mapping->rawEventName] = true;
            }
        }

        $seen = [];

        foreach ($input->mappings as $mapping) {
            $raw = $mapping['raw_event_name'];

            // Within this definition too: the same name twice would count twice.
            if (isset($taken[$raw]) || isset($seen[$raw])) {
                throw InvalidEventDefinition::duplicateRawName($raw);
            }

            $seen[$raw] = true;
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(EventDefinitionView $definition): array
    {
        return [
            'name' => $definition->name,
            'normalized_event' => $definition->normalizedEvent->value,
            'is_conversion' => $definition->isConversion,
            'is_primary' => $definition->isPrimary,
            'expects_value' => $definition->expectsValue,
            'mappings' => array_map(
                static fn (EventMappingView $mapping): string => $mapping->rawEventName,
                $definition->mappings,
            ),
        ];
    }
}
