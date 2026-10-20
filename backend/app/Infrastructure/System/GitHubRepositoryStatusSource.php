<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\System\Contract\RepositoryStatusSource;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The public GitHub API, unauthenticated: the repository is public, so no credential is needed and none is held.
 *
 * One request per branch and workflow, cached for five minutes — a status panel opened by several administrators is
 * still one request, and the unauthenticated limit (60 an hour per address) is never approached. A failure is
 * cached for a minute only, so an outage is retried soon but never hammered.
 */
final readonly class GitHubRepositoryStatusSource implements RepositoryStatusSource
{
    public function __construct(
        private Http $http,
        private Cache $cache,
        private string $repository,
    ) {}

    public function latestRun(string $branch, string $workflow): ?array
    {
        $key = 'github-status:'.sha1($this->repository.'|'.$branch.'|'.$workflow);

        /** @var array{state: string, sha: string|null}|false|null $cached */
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $result = $this->fetch($branch, $workflow);
        $this->cache->put($key, $result ?? false, $result === null ? 60 : 300);

        return $result;
    }

    /** @return array{state: string, sha: string|null}|null */
    private function fetch(string $branch, string $workflow): ?array
    {
        try {
            $response = $this->http->timeout(5)->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->get("https://api.github.com/repos/{$this->repository}/actions/runs", [
                    'branch' => $branch,
                    'per_page' => 10,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var list<array<string, mixed>> $runs */
        $runs = (array) $response->json('workflow_runs', []);

        foreach ($runs as $run) {
            if (($run['name'] ?? null) !== $workflow) {
                continue;
            }

            $sha = is_string($run['head_sha'] ?? null) ? substr($run['head_sha'], 0, 7) : null;

            if (($run['status'] ?? null) !== 'completed') {
                return ['state' => 'amber', 'sha' => $sha];
            }

            return match ($run['conclusion'] ?? null) {
                'success' => ['state' => 'green', 'sha' => $sha],
                'failure', 'timed_out' => ['state' => 'red', 'sha' => $sha],
                default => ['state' => 'grey', 'sha' => $sha],
            };
        }

        return null;
    }
}
