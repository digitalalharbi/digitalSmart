<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tags;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Search\Contract\SearchReadModel;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tags\Contract\TagStore;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agency's own vocabulary for its work.
 *
 * Tags are internal and never reach a client. Applying one is a write to an entity the actor must be able to
 * *see* — resolved through the same visibility-scoped lookup shortcuts use — so a tag cannot be used to probe
 * for the existence of a project somebody is not on: an entity outside their scope is a 404.
 */
final class TagController extends Controller
{
    private const array TYPES = ['client', 'project', 'campaign'];

    public function __construct(
        private readonly TagStore $tags,
        private readonly ActorContext $actor,
        private readonly AuthorizationGuard $guard,
        private readonly VisibilityResolver $visibility,
        private readonly SearchReadModel $search,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(): JsonResponse
    {
        /*
         * `clients.view`, which is the agency's — not `projects.view_assigned`, which a client's own users
         * hold for the projects they may read. Tags are the agency's private vocabulary; a client seeing
         * "Problem client" next to their own project would be exactly the leak this model exists to prevent.
         */
        $this->guard->ensure(Permission::ClientsView);

        return response()->json(['data' => $this->tags->all($this->actor->workspaceId()->value)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard->ensure(Permission::ProjectsUpdate);
        $validated = $request->validate(['name' => ['required', 'string', 'min:1', 'max:60']]);

        $created = $this->tags->create($this->actor->workspaceId()->value, (string) $validated['name']);

        if ($created === null) {
            return response()->json(['message' => 'A tag with that name already exists.', 'code' => 'tag_exists'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $created + ['usage' => 0]], Response::HTTP_CREATED);
    }

    public function destroy(string $tag): JsonResponse
    {
        $this->guard->ensure(Permission::ProjectsUpdate);
        $this->tags->delete($this->actor->workspaceId()->value, $tag);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function apply(string $tag, string $type, string $id): JsonResponse
    {
        return $this->change($tag, $type, $id, true);
    }

    /**
     * Tag many things at once. Each must be visible to the actor; the rest are reported as skipped rather than
     * failing the whole request, and are indistinguishable from absent.
     */
    public function applyMany(Request $request, string $tag, string $type): JsonResponse
    {
        $this->guard->ensure(Permission::ProjectsUpdate);

        if (! in_array($type, self::TYPES, true)) {
            return response()->json(['message' => 'Unknown type.', 'code' => 'invalid_type', 'allowed' => self::TYPES], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid'],
        ]);

        $workspace = $this->actor->workspaceId()->value;

        if (! $this->tags->exists($workspace, $tag)) {
            throw ResourceNotFound::of('Tag');
        }

        /** @var list<string> $ids */
        $ids = array_values(array_unique(array_filter((array) $validated['ids'], 'is_string')));

        $found = $this->search->lookup(
            $workspace,
            $this->visibility->visibleProjectIds(),
            array_map(static fn (string $id): array => ['type' => $type, 'id' => $id], $ids),
        );

        $visible = array_map(static fn ($hit): string => $hit->id, $found);

        foreach ($visible as $id) {
            $this->tags->apply($workspace, $tag, $type, $id);
            $this->audit->record(new AuditEntry(
                workspaceId: $this->actor->workspaceId(),
                actorId: $this->actor->userId(),
                action: AuditAction::TagApplied,
                auditableType: $type,
                auditableId: $id,
                summary: 'Applied an internal tag.',
                changes: ['tag_id' => $tag],
            ));
        }

        return response()->json(['data' => [
            'applied' => $visible,
            'skipped' => array_values(array_diff($ids, $visible)),
        ]]);
    }

    public function remove(string $tag, string $type, string $id): JsonResponse
    {
        return $this->change($tag, $type, $id, false);
    }

    private function change(string $tag, string $type, string $id, bool $apply): JsonResponse
    {
        $this->guard->ensure(Permission::ProjectsUpdate);

        if (! in_array($type, self::TYPES, true)) {
            return response()->json(['message' => 'Unknown type.', 'code' => 'invalid_type', 'allowed' => self::TYPES], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $workspace = $this->actor->workspaceId()->value;

        if (! $this->tags->exists($workspace, $tag)) {
            throw ResourceNotFound::of('Tag');
        }

        if ($this->search->lookup($workspace, $this->visibility->visibleProjectIds(), [['type' => $type, 'id' => $id]]) === []) {
            throw ResourceNotFound::of(ucfirst($type));
        }

        $apply ? $this->tags->apply($workspace, $tag, $type, $id) : $this->tags->remove($workspace, $tag, $type, $id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $apply ? AuditAction::TagApplied : AuditAction::TagRemoved,
            auditableType: $type,
            auditableId: $id,
            summary: $apply ? 'Applied an internal tag.' : 'Removed an internal tag.',
            changes: ['tag_id' => $tag],
        ));

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
