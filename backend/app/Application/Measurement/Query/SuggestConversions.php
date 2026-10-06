<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry;
use App\Application\Measurement\DTO\ConversionSuggestion;
use App\Application\Measurement\DTO\KeyEventRecord;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Measurement\NormalizedEvent;

/**
 * What a property already treats as a key event, offered for a person to confirm.
 *
 * This is the one place in the product where an event name is connected to a meaning without a
 * human having said so, and it is allowed only because of three constraints:
 *
 *  1. **It proposes; it never writes.** Nothing is counted until somebody saves a definition.
 *  2. **The map is Google's published vocabulary, not a guess.** Each entry below is an event
 *     Google documents with a defined meaning. A name that is not there gets a null, and the
 *     person picks — including names that look obvious.
 *  3. **It says what is already mapped**, so confirming the list twice cannot create a second
 *     definition claiming the same raw name.
 */
final readonly class SuggestConversions
{
    /**
     * Google's documented events, mapped to this product's vocabulary.
     *
     * Sources: the GA4 recommended events reference and the GA4 ecommerce measurement guide.
     * Deliberately short. `generate_lead`, `page_view`, `session_start`, `form_start` and
     * `form_submit` are **absent on purpose**: they are not documented on either page, and a
     * suggestion this product cannot cite is a guess wearing a citation's clothes. The cost of
     * leaving them out is one dropdown choice; the cost of getting one wrong is a conversion
     * that counts the wrong thing for a year.
     *
     * @var array<string, NormalizedEvent>
     */
    private const array DOCUMENTED = [
        'purchase' => NormalizedEvent::Purchase,
        'view_item' => NormalizedEvent::ViewItem,
        'add_to_cart' => NormalizedEvent::AddToCart,
        'begin_checkout' => NormalizedEvent::BeginCheckout,
        'sign_up' => NormalizedEvent::Signup,
    ];

    public function __construct(
        private MeasurementSourceRepository $sources,
        private EventDefinitionRepository $definitions,
        private MeasurementSyncAdapterRegistry $adapters,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<ConversionSuggestion> */
    public function __invoke(string $sourceId): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $source = $this->sources->find($workspaceId, $this->visibility->visibleProjectIds(), $sourceId);

        if ($source === null) {
            throw ResourceNotFound::of('Measurement source');
        }

        if ($source->provider === null || ! $this->adapters->supports($source->provider)) {
            // Nothing to ask. An empty list is the honest answer, not an error.
            return [];
        }

        $connection = $this->connections->findForProvider($this->actor->workspaceId(), $source->provider);

        if ($connection === null) {
            return [];
        }

        $mapped = $this->mappedNames($workspaceId, $source->clientId, $source->projectId);

        $suggestions = [];

        foreach ($this->adapters->for($source->provider)->fetchKeyEvents(
            $connection->credentials(),
            $source->externalId,
        ) as $event) {
            $suggestions[] = $this->toSuggestion($event, $mapped);
        }

        return $suggestions;
    }

    /** @param array<string, true> $mapped */
    private function toSuggestion(KeyEventRecord $event, array $mapped): ConversionSuggestion
    {
        return new ConversionSuggestion(
            rawEventName: $event->eventName,
            normalizedEvent: self::DOCUMENTED[$event->eventName] ?? null,
            alreadyMapped: isset($mapped[$event->eventName]),
            isCustom: $event->isCustom,
            /*
             * Carried through because it changes what the number means: counted per session, two
             * purchases in one visit are one key event. Somebody reconciling that against an
             * order count would otherwise conclude the tracking is broken.
             */
            countedOncePerSession: $event->isCountedOncePerSession(),
            defaultValue: $event->defaultValue,
            defaultCurrency: $event->defaultCurrency,
        );
    }

    /**
     * Raw names already claimed by a definition in this scope.
     *
     * So confirming the same list twice cannot produce a second definition counting the same
     * event — the duplicate the command would refuse anyway, surfaced here as a flag instead of
     * as a validation error the person has to read.
     *
     * @return array<string, true>
     */
    private function mappedNames(string $workspaceId, string $clientId, ?string $projectId): array
    {
        $mapped = [];

        $definitions = $this->definitions->visibleTo(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $clientId,
            $projectId,
        );

        foreach ($definitions as $definition) {
            foreach ($definition->mappings as $mapping) {
                $mapped[$mapping->rawEventName] = true;
            }
        }

        return $mapped;
    }
}
