<?php

declare(strict_types=1);

namespace App\Domain\Journeys;

/**
 * What makes us think two records are the same person.
 *
 * The same discipline the attribution evidence carries, applied to identity instead of to credit — and for the
 * same reason. Deciding that an enquiry and an order came from one person changes what a client is told about
 * their funnel, and deciding it wrongly merges two people's histories into one record that nobody can
 * disentangle afterwards.
 *
 * So every link says what it rests on, and the weaker kinds are marked as weaker rather than presented as
 * certainty.
 */
enum IdentityEvidence: string
{
    /**
     * The store's own customer record is linked to the order.
     *
     * The strongest available, because the platform made the link rather than this product inferring it: the
     * customer signed in, or the shop matched them itself.
     */
    case PlatformCustomer = 'platform_customer';

    /** The same normalised phone number. In this market, the identifier people actually give consistently. */
    case ExactPhone = 'exact_phone';

    /** The same normalised e-mail address. */
    case ExactEmail = 'exact_email';

    /**
     * The same click identifier on both records.
     *
     * Strong about the *click* and weaker about the *person*: a shared device or a forwarded link puts one
     * person's click on another person's order.
     */
    case SharedClickIdentifier = 'shared_click_identifier';

    /** Nothing links them. The honest answer, and a common one. */
    case None = 'none';

    /** How much weight a link on this evidence can carry, 0–100. */
    public function confidence(): int
    {
        return match ($this) {
            self::PlatformCustomer => 95,
            self::ExactPhone => 85,
            self::ExactEmail => 80,
            // Deliberately below the contact matches: it identifies a click, not a person.
            self::SharedClickIdentifier => 45,
            self::None => 0,
        };
    }

    /**
     * Whether a link on this evidence is safe to treat as one person in a report.
     *
     * The line is drawn above the click identifier on purpose. A shared-device match is worth showing to
     * somebody who can judge it and is not worth counting in a funnel.
     */
    public function isReliable(): bool
    {
        return $this->confidence() >= 80;
    }

    public function label(): string
    {
        return match ($this) {
            self::PlatformCustomer => 'Store customer record',
            self::ExactPhone => 'Same phone number',
            self::ExactEmail => 'Same e-mail address',
            self::SharedClickIdentifier => 'Same click identifier',
            self::None => 'Not linked',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
