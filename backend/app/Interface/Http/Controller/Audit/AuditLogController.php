<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Audit;

use App\Application\Audit\DTO\AuditEntryView;
use App\Application\Audit\Query\ListAuditEntries;
use App\Application\Shared\DTO\Pagination;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\PaginatedCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    /** The workspace's audit trail, newest first. */
    public function index(Request $request, ListAuditEntries $listEntries): JsonResponse
    {
        $actions = $request->input('action', []);
        $actions = is_array($actions) ? $actions : [$actions];

        $page = $listEntries(
            $request->string('search')->value() ?: null,
            array_values(array_filter($actions, 'is_string')),
            $request->string('actor_id')->value() ?: null,
            $request->has('security_only') ? $request->boolean('security_only') : null,
            new Pagination(
                page: $request->integer('page', 1),
                perPage: $request->integer('per_page', Pagination::DEFAULT_PER_PAGE),
            ),
        );

        return PaginatedCollection::response($page, static fn (object $entry): array => [
            'id' => $entry instanceof AuditEntryView ? $entry->id : '',
            'action' => $entry instanceof AuditEntryView ? $entry->action : '',
            'group' => $entry instanceof AuditEntryView ? $entry->group : '',
            'is_security_event' => $entry instanceof AuditEntryView && $entry->isSecurityEvent,
            'actor' => $entry instanceof AuditEntryView
                ? ['id' => $entry->actorId, 'name' => $entry->actorName]
                : null,
            'auditable' => $entry instanceof AuditEntryView
                ? ['type' => $entry->auditableType, 'id' => $entry->auditableId]
                : null,
            'summary' => $entry instanceof AuditEntryView ? $entry->summary : null,
            'changes' => $entry instanceof AuditEntryView ? $entry->changes : [],
            'ip_address' => $entry instanceof AuditEntryView ? $entry->ipAddress : null,
            'request_id' => $entry instanceof AuditEntryView ? $entry->requestId : null,
            'created_at' => $entry instanceof AuditEntryView ? $entry->createdAt : null,
        ]);
    }
}
