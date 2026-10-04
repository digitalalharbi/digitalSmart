<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Client;

final class UpdateClientRequest extends ClientRequest
{
    // Same shape as creation, minus is_demo, which is immutable after the fact.
}
