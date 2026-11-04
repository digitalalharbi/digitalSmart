<?php

declare(strict_types=1);

namespace App\Application\Notifications\Mail;

/** A message ready to hand to a mail provider: a subject, an HTML body and the plain-text alternative. */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
