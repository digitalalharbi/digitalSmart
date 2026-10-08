<?php

declare(strict_types=1);

namespace App\Application\Intelligence\DTO;

/**
 * The prose around a set of findings, and an honest account of who wrote it.
 *
 * `writtenBy` is on every response for the same reason every figure in this product carries a source state: a
 * reader is entitled to know whether a sentence was composed by a language model or assembled by the product from
 * its own findings. Both are legitimate. Presenting one as the other is not.
 *
 * `modelRejected` is the case worth building a field for. When a model returns prose containing a number the
 * evidence does not support, the narrative is **discarded** and the product's own sentences are used instead — and
 * the response says so, rather than quietly serving the fallback as though nothing had happened. A module that
 * hid that would be a module whose guard nobody could tell was working.
 */
final readonly class Narrative
{
    private function __construct(
        public string $text,
        /** 'model' or 'product'. */
        public string $writtenBy,
        /** The model's name, when one wrote it. */
        public ?string $model,
        /** True when a model answered and its answer was refused. */
        public bool $modelRejected,
        /** Why it was refused, for the record. */
        public ?string $rejectionReason,
    ) {}

    public static function fromModel(string $text, string $model): self
    {
        return new self($text, 'model', $model, false, null);
    }

    public static function fromProduct(string $text): self
    {
        return new self($text, 'product', null, false, null);
    }

    public static function refused(string $fallback, string $model, string $reason): self
    {
        return new self($fallback, 'product', $model, true, $reason);
    }
}
