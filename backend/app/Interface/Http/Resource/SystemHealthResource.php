<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource;

use App\Application\Shared\DTO\ServiceHealth;
use App\Application\Shared\DTO\SystemHealthReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SystemHealthReport
 *
 * @property-read SystemHealthReport $resource
 */
final class SystemHealthResource extends JsonResource
{
    public function __construct(SystemHealthReport $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'application' => $report->application,
            'version' => $report->version,
            'environment' => $report->environment,
            'status' => $report->status()->value,
            'services' => array_map(
                static fn (ServiceHealth $service): array => [
                    'name' => $service->name,
                    'status' => $service->status->value,
                    'detail' => $service->detail,
                ],
                $report->services,
            ),
        ];
    }
}
