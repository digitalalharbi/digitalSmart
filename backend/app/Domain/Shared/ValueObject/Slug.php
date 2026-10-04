<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\InvalidSlug;

/** URL-safe identifier, unique within its workspace. */
final readonly class Slug
{
    public const MAX_LENGTH = 80;

    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(public string $value)
    {
        if ($this->value === '' || strlen($this->value) > self::MAX_LENGTH) {
            throw InvalidSlug::forLength($this->value, self::MAX_LENGTH);
        }

        if (preg_match(self::PATTERN, $this->value) !== 1) {
            throw InvalidSlug::forFormat($this->value);
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Derive a slug from free text.
     *
     * Non-Latin input (Arabic client names are common here) can slugify to an
     * empty string, so the caller must supply a fallback that is already valid.
     */
    public static function fromText(string $text, string $fallback): self
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $ascii = $ascii === false ? '' : $ascii;

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-'));
        $slug = (string) preg_replace('/-+/', '-', $slug);

        if ($slug === '') {
            return new self($fallback);
        }

        return new self(substr($slug, 0, self::MAX_LENGTH));
    }

    public function equals(?self $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
