<?php

declare(strict_types=1);

namespace App\Application\Tracking\Service;

use App\Application\Shared\Contract\Clock;
use App\Application\Tracking\Contract\ConversionMappingRepository;
use App\Application\Tracking\Contract\DispatchQueue;
use App\Application\Tracking\Contract\ProviderDispatchStore;
use App\Application\Tracking\Contract\TrackingPolicyRepository;
use App\Application\Tracking\DTO\DispatchContext;
use App\Domain\Integration\Provider;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\Dispatch\ProviderConversionCatalogue;
use App\Domain\Tracking\Dispatch\SkipReason;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;
use DateTimeImmutable;
use Throwable;

/**
 * A conversion happened: decide, for each mapping that wants it, whether it may be sent — and record the decision either way.
 *
 * A dispatch row is written for every match, with the reason when the answer is no. The gates are applied here and not in
 * the sender, so a conversion that must not leave (a test, a bot, a visitor who did not consent, a lead with no identifier
 * the provider needs) never reaches anything that could send it.
 *
 * Never throws into the caller: a form submission or a stage change has already happened, and failing it because a
 * marketing destination could not be told would punish the person who did the work.
 */
final readonly class ConversionDispatcher
{
    /** Later stages of a lead's life go as offline conversions; everything up to the form is a server event. */
    private const array OFFLINE = ['lead_qualified', 'appointment_booked', 'opportunity_created', 'sale_completed'];

    public function __construct(
        private ConversionMappingRepository $mappings,
        private ProviderDispatchStore $dispatches,
        private TrackingPolicyRepository $policies,
        private DispatchQueue $queue,
        private Clock $clock,
    ) {}

    public function enqueue(DispatchContext $context): void
    {
        try {
            $policy = $this->policies->for($context->projectId)['consent_policy'];

            foreach ($this->mappings->enabledFor($context->projectId, $context->event->value) as $mapping) {
                $provider = Provider::tryFrom($mapping['provider']);

                if ($provider === null) {
                    continue;
                }

                $needs = ProviderConversionCatalogue::for($provider)['needs_click_id'];
                $skip = SkipReason::gate($context->trafficClass, $context->consent, $policy, $needs, $context->clickIds);
                $id = $this->dispatches->open($context, $mapping, in_array($context->event->value, self::OFFLINE, true) ? 'offline' : 'server', $skip);

                if ($id !== null && $skip === null) {
                    $this->queue->push($id);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A moment in a lead's life, dispatched with the consent, traffic class and identifiers of the visit that produced it. */
    public function forLead(string $workspaceId, string $leadId, TrackingEventName $event, ?DateTimeImmutable $at = null): void
    {
        try {
            $found = $this->dispatches->visitOfLead($workspaceId, $leadId);

            if ($found === null) {
                return;
            }

            $visit = $found['visit'];
            $context = new DispatchContext(
                $workspaceId, $found['project_id'], $event, 'lead-'.$leadId.'-'.$event->value,
                $visit === null ? ConsentState::Unknown : ConsentState::from($visit['consent']),
                $visit === null ? TrafficClass::Valid : TrafficClass::from($visit['traffic_class']),
                $visit['click_ids'] ?? [],
                $at ?? $this->clock->now(),
                $leadId,
            );

            if ($visit === null) {
                $this->enqueueSkipped($context, SkipReason::NoTrackedVisit);

                return;
            }

            $this->enqueue($context);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function enqueueSkipped(DispatchContext $context, SkipReason $reason): void
    {
        foreach ($this->mappings->enabledFor($context->projectId, $context->event->value) as $mapping) {
            $this->dispatches->open($context, $mapping, in_array($context->event->value, self::OFFLINE, true) ? 'offline' : 'server', $reason);
        }
    }
}
