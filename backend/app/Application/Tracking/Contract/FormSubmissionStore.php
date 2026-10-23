<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\FormSubmissionRecord;

interface FormSubmissionStore
{
    /** Keep a submission once. Returns false when this event id was already kept for the project. */
    public function add(FormSubmissionRecord $record): bool;

    public function attachLead(string $submissionId, string $leadId): void;
}
