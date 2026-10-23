<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\FormSubmissionStore;
use App\Application\Tracking\DTO\FormSubmissionRecord;
use Illuminate\Support\Facades\DB;

final readonly class EloquentFormSubmissionStore implements FormSubmissionStore
{
    public function add(FormSubmissionRecord $r): bool
    {
        return DB::table('form_submissions')->insertOrIgnore([
            'id' => $r->id, 'workspace_id' => $r->workspaceId, 'project_id' => $r->projectId, 'form_id' => $r->formId,
            'event_id' => $r->eventId, 'visitor_id' => $r->visitorId, 'session_id' => $r->sessionId,
            'touchpoint_id' => $r->touchpointId, 'tracking_link_id' => $r->trackingLinkId,
            'utm' => json_encode($r->utm, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'click_ids' => json_encode($r->clickIds, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'payload' => json_encode($r->payload, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'consent_state' => $r->consent->value, 'traffic_class' => $r->trafficClass->value, 'locale' => $r->locale,
            'submitted_at' => $r->submittedAt->format('Y-m-d H:i:sP'),
        ]) > 0;
    }

    public function attachLead(string $submissionId, string $leadId): void
    {
        DB::table('form_submissions')->where('id', $submissionId)->update(['lead_id' => $leadId]);
    }
}
