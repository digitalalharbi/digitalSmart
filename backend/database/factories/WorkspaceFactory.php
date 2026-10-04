<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
final class WorkspaceFactory extends Factory
{
    /** @var class-string<Workspace> */
    protected $model = Workspace::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'default_locale' => 'en',
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'settings' => [],
        ];
    }
}
