<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FormRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\Exception\InvalidForm;
use App\Domain\Tracking\Form\FormDefinition;
use App\Domain\Tracking\Form\FormStatus;

/** Publish, unpublish or archive. Publishing re-checks that a lead-creating form can actually make a lead. */
final readonly class SetFormStatus
{
    public function __construct(
        private FormRepository $forms,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id, FormStatus $status): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $form = $this->forms->find($workspaceId, $this->visibility->visibleProjectIds(), $id) ?? throw ResourceNotFound::of('Form');

        if ($form->status === $status) {
            return;
        }

        if ($status === FormStatus::Published) {
            if ($form->fields === []) {
                throw new TrackingRefused('Add at least one field before publishing.');
            }

            try {
                if ($form->createsLead) {
                    FormDefinition::assertCanCreateLeads($form->fields);
                }
            } catch (InvalidForm $e) {
                throw new TrackingRefused($e->getMessage(), 0, $e);
            }
        }

        $this->forms->setStatus($workspaceId, $id, $status);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingFormSaved,
            auditableType: 'form',
            auditableId: $id,
            summary: sprintf('Form "%s" is now %s', $form->name, $status->value),
            changes: ['from' => $form->status->value, 'to' => $status->value],
        ));
    }
}
