<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolCallLog;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Agents\ActionRefused;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use Throwable;

/**
 * The one door every tool goes through — an agent, a routine, or an outside MCP client.
 *
 * It decides, in code and in this order: that the tool exists; that the caller holds *every* permission the tool
 * names; that the input matches the tool's schema (and carries nothing the schema does not name); that the caller is
 * not calling faster than the tool allows; and then runs it. Every call is written to the log whatever happened,
 * including refusals — a refused call is exactly what somebody reviewing an agent wants to see.
 *
 * Nothing here knows about a provider. A handler asks the use cases the screens use, and those decide the route,
 * the capability and the approval.
 */
final readonly class ToolGateway
{
    /** @param iterable<ToolHandler> $handlers */
    public function __construct(
        private iterable $handlers,
        private ActorContext $actor,
        private ToolCallLog $log,
        private Clock $clock,
        private ToolCaller $caller,
    ) {}

    /** @return list<ToolDefinition> */
    public function definitions(): array
    {
        $out = [];

        foreach ($this->handlers as $handler) {
            $out[] = $handler->definition();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, error?: array{code: string, message: string}}
     */
    public function call(string $tool, array $input, string $channel = 'internal', string $agent = 'assistant'): array
    {
        $this->caller->channel = $channel;
        $this->caller->agent = $agent;

        $started = hrtime(true);
        $workspaceId = $this->actor->workspaceId()->value;
        $userId = $this->actor->userId()->value;

        $finish = function (string $outcome, ?string $code) use ($workspaceId, $userId, $tool, $channel, $input, $started): void {
            $this->log->record($workspaceId, $userId, $tool, $channel, $this->redact($input), $outcome, $code, (int) ((hrtime(true) - $started) / 1_000_000));
        };

        $handler = $this->find($tool);

        if ($handler === null) {
            $finish('refused', 'unknown_tool');

            return $this->refusal('unknown_tool', 'There is no such tool.');
        }

        $definition = $handler->definition();

        foreach ($definition->permissions as $permission) {
            if (! $this->actor->permissions()->has($permission)) {
                $finish('refused', 'forbidden');

                return $this->refusal('forbidden', 'You do not have permission to use this tool.');
            }
        }

        $problem = $this->validate($definition, $input);

        if ($problem !== null) {
            $finish('refused', 'invalid_input');

            return $this->refusal('invalid_input', $problem);
        }

        /*
         * The rate limit is for people and outside clients. An internal agent is limited by what it is allowed to do —
         * the caps and the duplicate and cooldown checks — and a limit that silently starved a routine would read as
         * "nothing to report".
         */
        if ($channel !== 'agent' && $this->log->countSince($workspaceId, $userId, $tool, $this->clock->now()->modify('-1 minute')) >= $definition->callsPerMinute) {
            $finish('refused', 'rate_limited');

            return $this->refusal('rate_limited', 'Too many calls to this tool. Try again in a minute.');
        }

        try {
            $data = $handler($input);
            $finish('ok', null);

            return ['ok' => true, 'data' => $data];
        } catch (ActionRefused $refusal) {
            $finish('refused', $refusal->reasonCode);

            return $this->refusal($refusal->reasonCode, $refusal->getMessage());
        } catch (InvalidApprovalRequest $refusal) {
            $finish('refused', $refusal->reasonCode);

            return $this->refusal($refusal->reasonCode, $refusal->getMessage());
        } catch (AuthorizationDenied) {
            $finish('refused', 'forbidden');

            return $this->refusal('forbidden', 'You do not have permission to use this tool.');
        } catch (ResourceNotFound $missing) {
            /* The same answer for "does not exist" and "not yours": a tool must not be a way to probe for ids. */
            $finish('refused', 'not_found');

            return $this->refusal('not_found', $missing->getMessage());
        } catch (Throwable $failure) {
            report($failure);
            $finish('failed', 'failed');

            return $this->refusal('failed', 'The tool could not finish.');
        }
    }

    private function find(string $tool): ?ToolHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->definition()->name === $tool) {
                return $handler;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function validate(ToolDefinition $definition, array $input): ?string
    {
        foreach ($definition->required as $name) {
            if (! array_key_exists($name, $input) || $input[$name] === null || $input[$name] === '') {
                return sprintf('"%s" is required.', $name);
            }
        }

        foreach ($input as $name => $value) {
            $property = $definition->properties[$name] ?? null;

            if ($property === null) {
                /* An unknown field is refused, never ignored: silently dropping what a caller sent hides a mistake. */
                return sprintf('"%s" is not an input of this tool.', $name);
            }

            $type = (string) ($property['type'] ?? 'string');
            $ok = match ($type) {
                'string' => is_string($value) && mb_strlen($value) <= 2000,
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                default => false,
            };

            if (! $ok) {
                return sprintf('"%s" must be a %s.', $name, $type);
            }

            if (($property['format'] ?? null) === 'uuid' && ! (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1)) {
                return sprintf('"%s" must be an id.', $name);
            }

            if (isset($property['enum']) && is_array($property['enum']) && ! in_array($value, $property['enum'], true)) {
                return sprintf('"%s" must be one of: %s.', $name, implode(', ', $property['enum']));
            }

            if (is_numeric($value) && isset($property['min']) && $value < $property['min']) {
                return sprintf('"%s" is too small.', $name);
            }

            if (is_numeric($value) && isset($property['max']) && $value > $property['max']) {
                return sprintf('"%s" is too large.', $name);
            }
        }

        return null;
    }

    /**
     * Free text is kept out of the log's `input`: it can carry a client's words, and the log is read by more people
     * than the original request was.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function redact(array $input): array
    {
        return array_map(
            static fn (mixed $value): mixed => is_string($value) && mb_strlen($value) > 80 ? mb_substr($value, 0, 80).'…' : $value,
            $input,
        );
    }

    /** @return array{ok: false, error: array{code: string, message: string}} */
    private function refusal(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
