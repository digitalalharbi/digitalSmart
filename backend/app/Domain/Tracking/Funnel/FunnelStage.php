<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Funnel;

final readonly class FunnelStage
{
    public function __construct(
        public FunnelSource $source,
        public ?string $labelAr,
        public ?string $labelEn,
        /** An optional stage may legitimately be skipped; a gap before a *required* one is flagged as a tracking problem. */
        public bool $required,
    ) {}

    /** @return array{source: string, label_ar: ?string, label_en: ?string, required: bool} */
    public function toArray(): array
    {
        return ['source' => $this->source->value, 'label_ar' => $this->labelAr, 'label_en' => $this->labelEn, 'required' => $this->required];
    }
}
