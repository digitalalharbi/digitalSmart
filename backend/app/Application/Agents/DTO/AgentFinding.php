<?php

declare(strict_types=1);

namespace App\Application\Agents\DTO;

/**
 * What a specialist agent saw, ready to be put in front of a person.
 *
 * The code and its values are the finding; `message` is the English fallback. Evidence holds the figures the
 * finding rests on — never advice, never an instruction — and a client never sees it.
 */
final readonly class AgentFinding
{
    /**
     * @param  'watch'|'needs_attention'|'critical'  $severity
     * @param  array<string, int|string|float>  $params
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $code,
        public string $subjectKind,
        public string $subjectId,
        public string $subjectLabel,
        public ?string $clientId,
        public ?string $projectId,
        public string $severity,
        public string $message,
        public array $params = [],
        public array $evidence = [],
        public ?string $taskId = null,
    ) {}
}
