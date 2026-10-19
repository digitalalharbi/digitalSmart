<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Contract;

use App\Domain\Intelligence\ModelPurpose;

/**
 * Which model serves which purpose, for one workspace.
 *
 * Replaceable by configuration, never by code: the strong and fast models are named in settings or by the workspace's
 * own key. With no key anywhere, every purpose is `AWAITING_MODEL_CREDENTIALS` and the product's deterministic path
 * is the one that runs — nothing waits on a model to work.
 */
interface ModelRouter
{
    public function narrator(ModelPurpose $purpose, string $workspaceId): LanguageModel;

    /**
     * Which agents a question is for, chosen by the routing model from `$allowed` only; null when no model is
     * configured, it failed, or it named none of them.
     *
     * @param  list<string>  $allowed
     * @return list<string>|null
     */
    public function chooseAgents(string $question, array $allowed, string $workspaceId): ?array;

    /** @return list<array{purpose: string, tier: string, model: string, source: string, state: string}> */
    public function status(string $workspaceId): array;
}
