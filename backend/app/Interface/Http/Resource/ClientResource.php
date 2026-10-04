<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource;

use App\Application\Client\DTO\ClientView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ClientView $resource
 */
final class ClientResource extends JsonResource
{
    public function __construct(ClientView $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $client = $this->resource;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'status' => $client->status,
            'legal_name' => $client->legalName,
            'industry' => $client->industry,
            'website' => $client->website,
            'primary_contact_name' => $client->primaryContactName,
            'primary_contact_email' => $client->primaryContactEmail,
            'primary_contact_phone' => $client->primaryContactPhone,
            'default_currency' => $client->defaultCurrency,
            'notes' => $client->notes,
            // Surfaced so the UI can label demo records; never inferred client-side.
            'is_demo' => $client->isDemo,
            'project_count' => $client->projectCount,
            'created_at' => $client->createdAt,
            'updated_at' => $client->updatedAt,
        ];
    }
}
