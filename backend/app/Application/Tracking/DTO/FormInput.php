<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

final readonly class FormInput
{
    /** @param list<array<string, mixed>> $fields raw, as a builder sent them; validated by FormDefinition */
    public function __construct(
        public string $projectId,
        public string $name,
        public string $defaultLocale,
        public bool $createsLead,
        public array $fields,
        public ?string $submitLabelAr = null,
        public ?string $submitLabelEn = null,
        public ?string $thankYouAr = null,
        public ?string $thankYouEn = null,
        public ?string $redirectUrl = null,
        public string $theme = 'auto',
        public ?string $accent = null,
        public ?string $logoUrl = null,
    ) {}
}
