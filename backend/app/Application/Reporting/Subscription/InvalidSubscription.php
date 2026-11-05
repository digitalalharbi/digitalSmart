<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use DomainException;

/** A recipient that cannot be added, with a sentence that says what to change. */
final class InvalidSubscription extends DomainException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function reportIsInternal(): self
    {
        return new self('Only a client-safe report can be emailed to somebody outside the agency. Change the report\'s audience first.', 'report_is_internal');
    }

    public static function consentMissing(): self
    {
        return new self('Say how the recipient agreed to receive this report by email. Without that, the address is not written to.', 'consent_required');
    }

    public static function alreadySubscribed(): self
    {
        return new self('That address already receives this report.', 'already_subscribed');
    }

    public static function emailInvalid(): self
    {
        return new self('That is not an email address a message can be sent to.', 'email_invalid');
    }
}
