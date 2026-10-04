<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\ProjectStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    /** @var class-string<Project> */
    protected $model = Project::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // sentence() is locale-safe and returns a plain string.
        $name = rtrim(fake()->unique()->sentence(3), '.');
        $client = Client::factory();

        return [
            'client_id' => $client,
            // Kept consistent with the client's workspace by the state below.
            'workspace_id' => fn (array $attributes): string => (string) Client::query()
                ->whereKey($attributes['client_id'])
                ->value('workspace_id'),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => ProjectStatus::Active->value,
            'currency' => 'SAR',
            'is_demo' => false,
        ];
    }

    public function forClient(Client $client): self
    {
        return $this->state(fn (): array => [
            'client_id' => $client->getKey(),
            'workspace_id' => $client->getAttribute('workspace_id'),
        ]);
    }

    public function status(ProjectStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }
}
