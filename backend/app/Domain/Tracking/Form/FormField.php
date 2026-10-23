<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Form;

/**
 * One field of a form, as the public sees it and as the validator checks it.
 *
 * `mapsTo` is the lead field the answer fills: `name`, `email`, `phone`, `message`, or `custom.<key>`.
 */
final readonly class FormField
{
    /**
     * @param  list<array{value: string, label_ar: string|null, label_en: string|null}>  $options
     * @param  array<string, int|float|string>  $validation
     * @param  array{field: string, operator: string, value: string|null}|null  $visibility
     */
    public function __construct(
        public string $key,
        public FormFieldType $type,
        public int $position,
        public ?string $labelAr,
        public ?string $labelEn,
        public ?string $placeholderAr,
        public ?string $placeholderEn,
        public ?string $helpAr,
        public ?string $helpEn,
        public bool $required,
        public ?string $default,
        public array $options,
        public array $validation,
        public ?array $visibility,
        public ?string $mapsTo,
    ) {}

    public function label(string $locale): string
    {
        return ($locale === 'en' ? $this->labelEn : $this->labelAr) ?? $this->labelAr ?? $this->labelEn ?? $this->key;
    }
}
