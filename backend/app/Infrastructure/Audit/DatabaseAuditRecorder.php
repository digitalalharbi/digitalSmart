<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditEntry;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists audit entries, enriched with request metadata.
 *
 * Recording never fails the action it describes: if the write throws, the
 * failure is logged loudly and the business operation still completes. Losing
 * one audit row is bad; rolling back a client's work because of it is worse.
 */
final readonly class DatabaseAuditRecorder implements AuditRecorder
{
    /** Keys scrubbed from any recorded diff. */
    private const REDACTED = [
        'password', 'password_confirmation', 'current_password', 'token',
        'access_token', 'refresh_token', 'client_secret', 'api_key', 'secret',
    ];

    public function __construct(private Request $request) {}

    public function record(AuditEntry $entry): void
    {
        try {
            AuditLog::query()->create([
                'workspace_id' => $entry->workspaceId?->value,
                'actor_id' => $entry->actorId?->value,
                'action' => $entry->action->value,
                'auditable_type' => $entry->auditableType,
                'auditable_id' => $entry->auditableId,
                'summary' => $entry->summary,
                'changes' => $this->redact($entry->changes),
                'context' => $this->redact($entry->context),
                'ip_address' => $this->request->ip(),
                'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
                'request_id' => $this->requestId(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to record an audit entry.', [
                'action' => $entry->action->value,
                'auditable_id' => $entry->auditableId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED, true)) {
                $result[$key] = '[redacted]';

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    private function requestId(): ?string
    {
        $id = $this->request->attributes->get('request_id');

        return is_string($id) ? $id : null;
    }
}
