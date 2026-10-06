<?php

declare(strict_types=1);

namespace App\Domain\Leads;

/**
 * How a lead arrived.
 *
 * **Every one of these is a completed action by a person.** That is the rule this enum exists to carry,
 * and it is why there is no `whatsapp_click` or `form_view` among them: a click is not a conversation and
 * a form view is not a submission. Those are *signals*, they live in the measurement layer as counted
 * events, and nothing in this product turns one into a lead.
 *
 * The distinction is not pedantry. An agency that reports WhatsApp clicks as leads reports a cost per lead
 * that is a fraction of the real one, wins the next month's budget on it, and cannot explain afterwards
 * why the client's sales did not move.
 */
enum LeadSourceKind: string
{
    /** A form was submitted. Not opened, not started — submitted. */
    case FormSubmission = 'form_submission';

    /** A message was received. Somebody wrote something. */
    case WhatsAppMessage = 'whatsapp_message';

    /** A call was answered, or a voicemail left. A dialled number that rang out is not this. */
    case PhoneCall = 'phone_call';

    /** A live-chat conversation took place. */
    case Chat = 'chat';

    /** An order from a store, which is a customer and therefore also a lead for anything else. */
    case StoreOrder = 'store_order';

    /** Somebody at the agency or the client entered it. Honest, and not to be reported as measured. */
    case Manual = 'manual';

    /** A bulk import, usually of history. Carries no attribution anybody should trust. */
    case Import = 'import';

    /** Came from a CRM this product reads. */
    case Crm = 'crm';

    /**
     * Whether this source's leads were measured rather than entered.
     *
     * A manual entry and an import are real leads and must not be presented as campaign results: nobody
     * knows where they came from, and attributing them would be attributing a guess.
     */
    public function isMeasured(): bool
    {
        return $this !== self::Manual && $this !== self::Import;
    }

    public function label(): string
    {
        return match ($this) {
            self::FormSubmission => 'Form submission',
            self::WhatsAppMessage => 'WhatsApp message',
            self::PhoneCall => 'Phone call',
            self::Chat => 'Live chat',
            self::StoreOrder => 'Store order',
            self::Manual => 'Entered by hand',
            self::Import => 'Imported',
            self::Crm => 'CRM',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
