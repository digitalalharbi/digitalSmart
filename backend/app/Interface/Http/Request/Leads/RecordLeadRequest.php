<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Leads;

use App\Application\Leads\Command\RecordLead;
use App\Application\Leads\DTO\LeadInput;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Leads\LeadSourceKind;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a lead's shape.
 *
 * `source_kind` is restricted to the kinds a person may record by hand. `import` and `crm` are deliberately
 * not among them: those arrive from an integration, and letting somebody type one would let a hand-entered
 * row claim to have come from a system — which is the one thing that would make the `is_measured` flag
 * meaningless.
 */
final class RecordLeadRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $kinds = array_map(
            static fn (LeadSourceKind $kind): string => $kind->value,
            RecordLead::manualSourceKinds(),
        );

        return [
            'client_id' => ['required', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'source_kind' => ['required', Rule::in($kinds)],
            'source_detail' => ['sometimes', 'nullable', 'string', 'max:191'],
            /*
             * All optional, and at least one of a name, an e-mail or a phone is required below. An enquiry
             * with no way to reach the person is still worth recording — it happened — but one with no
             * identifying information at all is a row nobody can act on.
             */
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'message' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'received_at' => ['sometimes', 'nullable', 'date'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            // Whatever the lead carried. Never invented by the server.
            'utm_source' => ['sometimes', 'nullable', 'string', 'max:191'],
            'utm_medium' => ['sometimes', 'nullable', 'string', 'max:191'],
            'utm_campaign' => ['sometimes', 'nullable', 'string', 'max:191'],
            'utm_content' => ['sometimes', 'nullable', 'string', 'max:191'],
            'utm_term' => ['sometimes', 'nullable', 'string', 'max:191'],
            'click_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'landing_page' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'referrer' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    /** @return list<callable(\Illuminate\Validation\Validator): void> */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator): void {
                $hasContact = $this->filled('name') || $this->filled('email') || $this->filled('phone');

                if (! $hasContact) {
                    /*
                     * A lead nobody can identify is a row nobody can work. Refused with a message that says
                     * what to add rather than naming three fields as individually required, because any one
                     * of them is enough.
                     */
                    $validator->errors()->add(
                        'name',
                        'Give a name, an e-mail or a phone number. A lead nobody can identify cannot be worked.',
                    );
                }
            },
        ];
    }

    public function toInput(): LeadInput
    {
        $value = $this->filled('value') ? $this->string('value')->value() : null;

        return new LeadInput(
            clientId: $this->string('client_id')->value(),
            sourceKind: LeadSourceKind::tryFrom($this->string('source_kind')->value()) ?? LeadSourceKind::Manual,
            // Defaults to now, because a lead recorded without a time arrived when somebody recorded it.
            receivedAt: $this->filled('received_at')
                ? new DateTimeImmutable($this->string('received_at')->value())
                : new DateTimeImmutable,
            attribution: OrderAttribution::from(
                source: $this->nullableString('utm_source'),
                medium: $this->nullableString('utm_medium'),
                campaign: $this->nullableString('utm_campaign'),
                content: $this->nullableString('utm_content'),
                term: $this->nullableString('utm_term'),
                clickIdentifier: $this->nullableString('click_identifier'),
                referrer: $this->nullableString('referrer'),
            ),
            projectId: $this->nullableString('project_id'),
            sourceDetail: $this->nullableString('source_detail'),
            name: $this->nullableString('name'),
            email: $this->nullableString('email'),
            phone: $this->nullableString('phone'),
            message: $this->nullableString('message'),
            landingPage: $this->nullableString('landing_page'),
            /*
             * Minor units from a decimal string, so an API that takes "1500.00" and one that takes 150000
             * cannot be confused — the mistake is invisible in a request body and visible on an invoice.
             */
            valueMinor: $value === null ? null : (int) round(((float) $value) * 100),
            currency: $value === null ? null : strtoupper($this->string('currency')->value() ?: 'SAR'),
        );
    }

    private function nullableString(string $key): ?string
    {
        $value = trim($this->string($key)->value());

        return $value === '' ? null : $value;
    }
}
