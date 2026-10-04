<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource;

use App\Application\Project\DTO\ProjectView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProjectView $resource
 */
final class ProjectResource extends JsonResource
{
    public function __construct(ProjectView $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        return [
            'id' => $project->id,
            'client' => ['id' => $project->clientId, 'name' => $project->clientName],
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status,
            'currency' => $project->currency,
            'description' => $project->description,
            'starts_on' => $project->startsOn,
            'ends_on' => $project->endsOn,
            'is_demo' => $project->isDemo,
            'member_count' => $project->memberCount,
            'is_assigned' => $project->isAssigned,
            'created_at' => $project->createdAt,
            'updated_at' => $project->updatedAt,
        ];
    }
}
