<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * What somebody asking for an account says they want to do.
 *
 * Three, because those are the three ways a visitor can relate to the product: run their own campaigns, run
 * campaigns for clients of their own, or have the agency run them. The answer decides who replies and what is offered
 * first; it grants nothing.
 */
enum AccessRequestNeed: string
{
    case SelfManaged = 'self_managed';
    case AgencyClients = 'agency_clients';
    case AdServices = 'ad_services';

    public function label(): string
    {
        return match ($this) {
            self::SelfManaged => 'Manage my own campaigns',
            self::AgencyClients => 'Manage campaigns for my clients',
            self::AdServices => 'I need advertising services',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
