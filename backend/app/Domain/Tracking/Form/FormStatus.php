<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Form;

enum FormStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /** Only a published form accepts submissions; a draft is invisible to the public. */
    public function acceptsSubmissions(): bool
    {
        return $this === self::Published;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
