<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Tracking\Contract\FormRepository;
use App\Application\Tracking\DTO\FormView;

/** A published form by its public token. A draft, an archived form and one that never existed are the same answer: null. */
final readonly class GetPublicForm
{
    public function __construct(private FormRepository $forms) {}

    public function __invoke(string $token): ?FormView
    {
        $form = $this->forms->findByToken($token);

        return $form !== null && $form->status->acceptsSubmissions() ? $form : null;
    }
}
