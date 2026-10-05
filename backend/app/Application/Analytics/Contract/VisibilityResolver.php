<?php

declare(strict_types=1);

namespace App\Application\Analytics\Contract;

/**
 * Which projects an actor's analytics may cover.
 *
 * Separate from the read model because it answers an authorization question, not
 * a reporting one: a client viewer and a media buyer looking at the same screen
 * must see different data, and that narrowing has to happen before any SQL that
 * aggregates spend.
 */
interface VisibilityResolver
{
    /**
     * Project ids the actor may see, or null for every project in the workspace.
     *
     * Null is "unrestricted", not "none" — an empty list means the actor is
     * assigned to nothing and must therefore see nothing, which is a different
     * answer and has to stay distinguishable.
     *
     * @return list<string>|null
     */
    public function visibleProjectIds(): ?array;
}
