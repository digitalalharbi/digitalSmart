<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * HubSpot.
 *
 * OAuth 2.0: a HubSpot account installs an app, which is granted scopes per object. For what this product reads
 * that is `crm.objects.deals.read`, `crm.objects.contacts.read` and the pipeline read for deals. Every request
 * carries `Authorization: Bearer {access_token}` against `https://api.hubapi.com`.
 *
 * The contracts read for this integration, on 2026-10-08:
 *
 *  - `GET /crm/v3/objects/deals` and `/crm/v3/objects/deals/{id}` — cursor paginated with `after`, a `limit` of
 *    at most 100, and a `properties` parameter naming the properties to return. A deal's stage arrives as
 *    `properties.dealstage` and is an **internal stage id**, not a label: HubSpot's own documentation says the
 *    internal id must be used, and the label is a thing people rename.
 *  - `GET /crm/v3/pipelines/deals` and `/crm/v3/pipelines/deals/{pipelineId}/stages` — each stage carries `id`,
 *    `label`, `displayOrder` and a `metadata` object which for deals holds `probability` (0.0–1.0) and
 *    `isClosed`. **That metadata is the only evidence this product will read a meaning from**; see
 *    `CrmStageMeaning`.
 *  - `GET /crm/v4/objects/deal/{dealId}/associations/contact` — the contacts on a deal, returning `toObjectId`
 *    with `associationTypes` carrying a `category` and a `typeId`; the documented HubSpot-defined type for
 *    deal-to-contact is `3`.
 *  - `GET /crm/v3/objects/contacts/{id}` — `properties` again, with `email`, `firstname`, `lastname` among the
 *    standard ones.
 *
 * Rate limits are published rather than guessed: 190 requests per app per 10 seconds, and a daily figure that
 * depends on the installing account's tier. Every response carries `X-HubSpot-RateLimit-Remaining` and
 * `X-HubSpot-RateLimit-Interval-Milliseconds`; a 429 carries no documented `Retry-After`, so a retry here has to
 * pace itself from the interval header rather than wait to be told.
 *
 * BLOCKED_EXTERNAL: needs an app in a HubSpot developer account with a client id and secret, and a HubSpot
 * account that installs it and grants the read scopes. Self-service for private use, so the blocker is an account
 * and a consent rather than a programme admission.
 */
final class HubSpotAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::HubSpot;
    }
}
