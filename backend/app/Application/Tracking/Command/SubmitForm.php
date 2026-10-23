<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Leads\Command\AutomateNewLead;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Tracking\Contract\FormRepository;
use App\Application\Tracking\Contract\FormSubmissionStore;
use App\Application\Tracking\Contract\OpaqueTokens;
use App\Application\Tracking\Contract\TrackingEventStore;
use App\Application\Tracking\DTO\DispatchContext;
use App\Application\Tracking\DTO\FormSubmissionRecord;
use App\Application\Tracking\DTO\FormView;
use App\Application\Tracking\DTO\Touchpoint;
use App\Application\Tracking\Service\ConversionDispatcher;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\Form\FormValidator;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;
use DateTimeImmutable;
use Throwable;

/**
 * Somebody submits a published form.
 *
 * Public, so the answer is deliberately small: `null` means there is no such form (unpublished, archived and never
 * existed are the same answer); `errors` are the field problems the visitor can fix; and otherwise the submission was
 * kept. The submission is written before anything else and is never lost to a later failure — a lead that could not be
 * created is logged and the visitor still sees their thank-you, because the record they asked to make exists.
 */
final readonly class SubmitForm
{
    public function __construct(
        private FormRepository $forms,
        private FormSubmissionStore $submissions,
        private TrackingEventStore $events,
        private LeadRepository $leads,
        private IdentifierGenerator $ids,
        private OpaqueTokens $tokens,
        private Clock $clock,
        private ConversionDispatcher $conversions,
        private AutomateNewLead $automate,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @return array{errors: array<string, string>, thank_you: array{ar: ?string, en: ?string}, redirect_url: ?string}|null
     */
    public function __invoke(string $token, array $values, ?string $touchpointId, string $eventId, ?ConsentState $consent, string $locale): ?array
    {
        $form = $this->forms->findByToken($token);

        if ($form === null || ! $form->status->acceptsSubmissions()) {
            return null;
        }

        $checked = FormValidator::validate($form->fields, $values);
        $response = ['errors' => $checked['errors'], 'thank_you' => ['ar' => $form->thankYouAr, 'en' => $form->thankYouEn], 'redirect_url' => $form->redirectUrl];

        if ($checked['errors'] !== []) {
            return $response;
        }

        $now = $this->clock->now();
        $touchpoint = $touchpointId !== null ? $this->events->touchpoint($touchpointId) : null;
        /* A touchpoint from another project says nothing about this form: ignored, not trusted. */
        $touchpoint = $touchpoint !== null && $touchpoint->projectId === $form->projectId ? $touchpoint : null;
        $attribution = $touchpoint !== null ? $this->events->attributionOf($touchpoint) : ['utm' => [], 'click_ids' => [], 'referrer_host' => null, 'landing_path' => null];
        $visitor = $touchpoint->visitorId ?? $this->tokens->make(20);
        $state = $consent ?? $touchpoint->consent ?? ConsentState::Unknown;
        $class = $touchpoint->trafficClass ?? TrafficClass::Valid;

        $record = new FormSubmissionRecord(
            $this->ids->generate(), $form->workspaceId(), $form->projectId, $form->id, $eventId,
            $visitor, $touchpoint?->sessionId, $touchpoint?->touchpointId, $touchpoint?->trackingLinkId,
            $checked['clean'], $attribution['utm'], $attribution['click_ids'], $state, $class,
            in_array($locale, ['ar', 'en'], true) ? $locale : $form->defaultLocale, $now,
        );

        if (! $this->submissions->add($record)) {
            return $response;
        }

        $this->events->recordServerEvent($form->workspaceId(), $form->projectId, $touchpoint, $touchpoint?->trackingLinkId, TrackingEventName::FormSubmit, $eventId, $visitor, $state, $class, $now);

        /* The submission's own event id is the one the page pushed to its data layer, so a provider can de-duplicate the two. */
        $this->conversions->enqueue(new DispatchContext($form->workspaceId(), $form->projectId, TrackingEventName::FormSubmit, $eventId, $state, $class, $attribution['click_ids'], $now));

        /* A test visit leaves its submission for the debug console and makes no lead: it is not a person. */
        if ($form->createsLead && $class !== TrafficClass::Test) {
            $this->createLead($form, $record, $attribution, $now, $record->locale);
        }

        return $response;
    }

    /** @param array{utm: array<string, string>, click_ids: array<string, string>, referrer_host: ?string, landing_path: ?string} $attribution */
    private function createLead(FormView $form, FormSubmissionRecord $record, array $attribution, DateTimeImmutable $now, string $locale): void
    {
        try {
            [$core, $custom] = $this->mapped($form, $record->payload);
            $click = $attribution['click_ids'] === [] ? null : (string) array_values($attribution['click_ids'])[0];

            $lead = $this->leads->record($form->workspaceId(), new LeadInput(
                clientId: $form->clientId,
                sourceKind: LeadSourceKind::FormSubmission,
                receivedAt: $now,
                attribution: OrderAttribution::from(
                    $attribution['utm']['utm_source'] ?? null,
                    $attribution['utm']['utm_medium'] ?? null,
                    $attribution['utm']['utm_campaign'] ?? null,
                    $attribution['utm']['utm_content'] ?? null,
                    $attribution['utm']['utm_term'] ?? null,
                    $click,
                    null,
                    $attribution['referrer_host'],
                ),
                projectId: $form->projectId,
                sourceDetail: $form->name,
                name: $core['name'] ?? null,
                email: $core['email'] ?? null,
                phone: $core['phone'] ?? null,
                message: $core['message'] ?? null,
                landingPage: $attribution['landing_path'],
                custom: $custom,
            ));

            $this->submissions->attachLead($record->id, $lead->id);

            /* One lead_created per lead: a repeat submission that found the same person created nothing new. */
            if ($lead->receivedAt->getTimestamp() >= $now->getTimestamp()) {
                $this->events->recordServerEvent(
                    $form->workspaceId(), $form->projectId, null, $record->trackingLinkId, TrackingEventName::LeadCreated,
                    'lead-'.$lead->id, $record->visitorId ?? '', $record->consent, $record->trafficClass, $now,
                );
                $this->conversions->enqueue(new DispatchContext($form->workspaceId(), $form->projectId, TrackingEventName::LeadCreated, 'lead-'.$lead->id, $record->consent, $record->trafficClass, $record->clickIds, $now, $lead->id));

                /* Only a lead this submission made: a repeat that found the same person is not handed out twice. */
                ($this->automate)($form->workspaceId(), $lead, $locale, $now);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{0: array<string, string>, 1: array<string, string>} core lead fields, custom answers
     */
    private function mapped(FormView $form, array $payload): array
    {
        $core = [];
        $custom = [];

        foreach ($form->fields as $field) {
            $value = $payload[$field->key] ?? null;

            if ($value === null || $field->mapsTo === null) {
                continue;
            }

            if (str_starts_with($field->mapsTo, 'custom.')) {
                $custom[substr($field->mapsTo, 7)] = $value;
            } else {
                $core[$field->mapsTo] = $value;
            }
        }

        return [$core, $custom];
    }
}
