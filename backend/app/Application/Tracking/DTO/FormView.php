<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use App\Domain\Tracking\Form\FormField;
use App\Domain\Tracking\Form\FormStatus;
use DateTimeImmutable;

/** A form as the agency sees it. The public sees `PublicForm`, which carries none of the identifiers here. */
final readonly class FormView
{
    /** @param list<FormField> $fields */
    public function __construct(
        public string $id,
        private string $workspace,
        public string $clientId,
        public string $clientName,
        public string $projectId,
        public string $projectName,
        public string $publicToken,
        public string $name,
        public FormStatus $status,
        public string $defaultLocale,
        public bool $createsLead,
        public ?string $submitLabelAr,
        public ?string $submitLabelEn,
        public ?string $thankYouAr,
        public ?string $thankYouEn,
        public ?string $redirectUrl,
        public string $theme,
        public ?string $accent,
        public ?string $logoUrl,
        public array $fields,
        public DateTimeImmutable $createdAt,
        public ?int $submissions = null,
    ) {}

    public function workspaceId(): string
    {
        return $this->workspace;
    }

    public function withSubmissions(int $count): self
    {
        return new self(
            $this->id, $this->workspace, $this->clientId, $this->clientName, $this->projectId, $this->projectName, $this->publicToken,
            $this->name, $this->status, $this->defaultLocale, $this->createsLead, $this->submitLabelAr, $this->submitLabelEn,
            $this->thankYouAr, $this->thankYouEn, $this->redirectUrl, $this->theme, $this->accent, $this->logoUrl,
            $this->fields, $this->createdAt, $count,
        );
    }
}
