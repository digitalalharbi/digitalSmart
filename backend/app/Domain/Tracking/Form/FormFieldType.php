<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Form;

enum FormFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Phone = 'phone';
    case Number = 'number';
    case Textarea = 'textarea';
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case Hidden = 'hidden';
    case Consent = 'consent';

    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::Radio], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
