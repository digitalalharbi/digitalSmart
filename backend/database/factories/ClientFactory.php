<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Client\ClientStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
final class ClientFactory extends Factory
{
    /** @var class-string<Client> */
    protected $model = Client::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => ClientStatus::Active->value,
            'industry' => fake()->randomElement(['Retail', 'Fintech', 'Hospitality', 'Healthcare']),
            'default_currency' => 'SAR',
            'is_demo' => false,
        ];
    }

    public function demo(): self
    {
        return $this->state(fn (): array => [
            'is_demo' => true,
            'name' => 'DEMO '.fake()->company(),
        ]);
    }

    public function status(ClientStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }
}
