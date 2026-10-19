<?php

declare(strict_types=1);

namespace App\Application\Automation\Command;

use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Automation\RuleMode;
use App\Domain\Shared\Exception\DomainException;

/**
 * Enable, disable, archive or change the mode of several rules at once — each through the same use case a single
 * edit goes through, so validation, the trust ladder and the change log all apply rule by rule. Auto is never
 * reachable in bulk (or at all, until authorised). Each rule answers for itself.
 */
final readonly class BulkRules
{
    public const int MAX = 100;

    public function __construct(
        private AutomationRuleRepository $rules,
        private SaveRule $save,
        private DeleteRule $delete,
        private ActorContext $actor,
    ) {}

    /**
     * @param  list<string>  $ids
     * @param  'enable'|'disable'|'archive'|'mode'  $operation
     * @return array{done: int, refused: int, results: list<array{id: string, ok: bool, reason: string|null}>}
     */
    public function __invoke(array $ids, string $operation, ?RuleMode $mode = null): array
    {
        $results = [];

        foreach (array_slice(array_values(array_unique($ids)), 0, self::MAX) as $id) {
            $rule = $this->rules->find($this->actor->workspaceId()->value, $id);

            if ($rule === null) {
                $results[] = ['id' => $id, 'ok' => false, 'reason' => 'not_found'];

                continue;
            }

            try {
                match ($operation) {
                    'archive' => ($this->delete)($id),
                    'enable' => ($this->save)(RuleInput::fromView($rule, isActive: true), $id),
                    'disable' => ($this->save)(RuleInput::fromView($rule, isActive: false), $id),
                    default => ($this->save)(RuleInput::fromView($rule, mode: $mode ?? $rule->mode), $id),
                };
                $results[] = ['id' => $id, 'ok' => true, 'reason' => null];
            } catch (AuthorizationDenied) {
                $results[] = ['id' => $id, 'ok' => false, 'reason' => 'forbidden'];
            } catch (ResourceNotFound) {
                $results[] = ['id' => $id, 'ok' => false, 'reason' => 'not_found'];
            } catch (DomainException $refused) {
                $results[] = ['id' => $id, 'ok' => false, 'reason' => $refused->errorCode()];
            }
        }

        $done = count(array_filter($results, static fn (array $r): bool => $r['ok']));

        return ['done' => $done, 'refused' => count($results) - $done, 'results' => $results];
    }
}
