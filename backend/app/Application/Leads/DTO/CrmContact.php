<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

/**
 * One person on a deal.
 *
 * Deliberately thin: a name, an email, a phone number. A CRM holds far more about a person than this product has
 * any reason to copy, and every field copied is a field that has to be protected, retained and eventually
 * deleted — see the retention note in `docs/SECURITY.md`. The lead record this becomes already holds the three
 * fields a media buyer uses to recognise somebody.
 */
final readonly class CrmContact
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
    ) {}
}
