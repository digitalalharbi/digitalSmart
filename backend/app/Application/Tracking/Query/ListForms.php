<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FormRepository;
use App\Application\Tracking\DTO\FormView;
use App\Domain\Access\Permission;

final readonly class ListForms
{
    public function __construct(
        private FormRepository $forms,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<FormView> */
    public function __invoke(?string $projectId = null): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $rows = $this->forms->list($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $projectId);
        $counts = $this->forms->submissionCounts(array_map(static fn (FormView $f): string => $f->id, $rows));

        return array_map(static fn (FormView $f): FormView => $f->withSubmissions($counts[$f->id] ?? 0), $rows);
    }

    public function one(string $id): FormView
    {
        $this->guard->ensure(Permission::TrackingView);

        $form = $this->forms->find($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $id)
            ?? throw ResourceNotFound::of('Form');

        return $form->withSubmissions($this->forms->submissionCounts([$id])[$id] ?? 0);
    }
}
