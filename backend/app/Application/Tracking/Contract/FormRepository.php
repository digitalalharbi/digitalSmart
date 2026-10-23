<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\FormInput;
use App\Application\Tracking\DTO\FormView;
use App\Domain\Tracking\Form\FormField;
use App\Domain\Tracking\Form\FormStatus;

interface FormRepository
{
    /**
     * @param  list<FormField>  $fields
     */
    public function create(string $workspaceId, string $clientId, string $token, string $createdBy, FormInput $input, array $fields): FormView;

    /** @param list<FormField> $fields */
    public function update(string $workspaceId, string $id, FormInput $input, array $fields): FormView;

    public function setStatus(string $workspaceId, string $id, FormStatus $status): void;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?FormView;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<FormView>
     */
    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $projectId): array;

    /** By public token, in no workspace in particular. Every status: the caller decides what to show. */
    public function findByToken(string $token): ?FormView;

    /** @return array{workspace_id: string, client_id: string}|null */
    public function tenantOf(string $formId): ?array;

    public function tokenExists(string $token): bool;

    /**
     * @param  list<string>  $formIds
     * @return array<string, int> submissions by form id
     */
    public function submissionCounts(array $formIds): array;
}
