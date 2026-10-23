<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FormRepository;
use App\Application\Tracking\Contract\OpaqueTokens;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\FormInput;
use App\Application\Tracking\DTO\FormView;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\DestinationUrl;
use App\Domain\Tracking\Exception\InvalidForm;
use App\Domain\Tracking\Exception\UnsafeDestination;
use App\Domain\Tracking\Form\FormDefinition;

/**
 * Create or change a form. Saving replaces the field set as a whole, which is how a builder works: the form is what it
 * shows, in the order it shows it.
 */
final readonly class SaveForm
{
    /** @param list<string> $ownHosts */
    public function __construct(
        private FormRepository $forms,
        private TrackingLinkRepository $links,
        private OpaqueTokens $tokens,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private array $ownHosts = [],
    ) {}

    public function __invoke(FormInput $input, ?string $id = null): FormView
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $clientId = $this->links->clientOfProject($workspaceId, $visible, $input->projectId) ?? throw ResourceNotFound::of('Project');

        if (! in_array($input->defaultLocale, ['ar', 'en'], true) || ! in_array($input->theme, ['auto', 'light', 'dark'], true)) {
            throw new TrackingRefused('Unknown language or theme.');
        }

        if ($input->accent !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $input->accent) !== 1) {
            throw new TrackingRefused('The accent colour is a six-digit hex value such as #C24A2C.');
        }

        try {
            $fields = FormDefinition::fieldsFrom($input->fields);

            if ($input->createsLead) {
                FormDefinition::assertCanCreateLeads($fields);
            }

            foreach (['redirectUrl' => $input->redirectUrl, 'logoUrl' => $input->logoUrl] as $url) {
                if ($url !== null && $url !== '') {
                    DestinationUrl::parse($url, $this->ownHosts);
                }
            }
        } catch (InvalidForm|UnsafeDestination $e) {
            throw new TrackingRefused($e->getMessage(), 0, $e);
        }

        if ($id === null) {
            $form = $this->forms->create($workspaceId, $clientId, $this->freshToken(), $this->actor->userId()->value, $input, $fields);
        } else {
            $existing = $this->forms->find($workspaceId, $visible, $id) ?? throw ResourceNotFound::of('Form');

            if ($existing->projectId !== $input->projectId) {
                throw new TrackingRefused('A form cannot move to another project: its submissions belong to the project it was made for.');
            }

            $form = $this->forms->update($workspaceId, $id, $input, $fields);
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingFormSaved,
            auditableType: 'form',
            auditableId: $form->id,
            summary: sprintf('%s form "%s"', $id === null ? 'Created' : 'Changed', $form->name),
            changes: ['project_id' => $form->projectId, 'fields' => count($fields), 'creates_lead' => $form->createsLead],
        ));

        return $form;
    }

    private function freshToken(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $token = $this->tokens->make(12);

            if (! $this->forms->tokenExists($token)) {
                return $token;
            }
        }

        throw new TrackingRefused('Could not make a unique form address; try again.');
    }
}
