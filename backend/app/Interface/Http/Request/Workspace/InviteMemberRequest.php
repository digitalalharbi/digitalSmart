<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Workspace;

use App\Domain\Access\SystemRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Whether the address already belongs to a member, and whether the role may be handed out at all,
 * are the use case's business — those are rules about a workspace rather than about a request body.
 *
 * The email is **not** checked for uniqueness here, deliberately: an address that already has an account is a
 * valid request that joins that person to this workspace, and a `unique` rule would refuse the one case this
 * endpoint most needs to handle.
 */
final class InviteMemberRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            /*
             * `rfc` only. The `dns` rule makes form validation depend on a DNS lookup — slow, flaky, and wrong
             * about real addresses: a company whose domain publishes no MX record still receives mail through
             * its A record, and refusing their colleague's address would be this product being confidently
             * incorrect about somebody else's email.
             */
            'email' => ['required', 'email:rfc', 'max:255'],
            /*
             * One of the system roles, by slug. `Rule::in` over the enum rather than a free string, so a typo is
             * a validation error on the form rather than a `404` from the role lookup.
             */
            'role' => ['required', 'string', Rule::in(SystemRole::values())],
        ];
    }
}
