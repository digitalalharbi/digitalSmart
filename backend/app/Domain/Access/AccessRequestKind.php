<?php

declare(strict_types=1);

namespace App\Domain\Access;

/** What the person wants. One table holds all of them; only an account request can lead to an invitation. */
enum AccessRequestKind: string
{
    case AccountAccess = 'account_access';
    case GeneralEnquiry = 'general_enquiry';
    case DemoRequest = 'demo_request';
    case Partnership = 'partnership';
    case Other = 'other';

    public function canBeApproved(): bool
    {
        return $this === self::AccountAccess;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
