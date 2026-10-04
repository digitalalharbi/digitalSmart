<?php

declare(strict_types=1);

use App\Domain\Client\ClientId;
use App\Domain\Identity\Email;
use App\Domain\Project\ProjectId;
use App\Domain\Shared\Exception\InvalidEmail;
use App\Domain\Shared\Exception\InvalidIdentifier;
use App\Domain\Shared\Exception\InvalidSlug;
use App\Domain\Shared\ValueObject\Slug;

describe('Identifier', function (): void {
    it('accepts a uuid', function (): void {
        $id = new ClientId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90');

        expect($id->value)->toBe('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90');
    });

    it('rejects anything that is not a uuid', function (string $value): void {
        expect(fn (): ClientId => new ClientId($value))->toThrow(InvalidIdentifier::class);
    })->with(['', 'abc', '123', '0193f0f4-2b7a-7cc0-9a1b', 'not-a-uuid-at-all-really-no']);

    it('does not treat different id types as equal', function (): void {
        $uuid = '0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90';

        // The whole point of typed ids: a client id is not a project id.
        expect((new ClientId($uuid))->equals(new ProjectId($uuid)))->toBeFalse()
            ->and((new ClientId($uuid))->equals(new ClientId($uuid)))->toBeTrue();
    });
});

describe('Slug', function (): void {
    it('accepts lowercase hyphenated values', function (): void {
        expect((new Slug('acme-retail-2026'))->value)->toBe('acme-retail-2026');
    });

    it('rejects malformed values', function (string $value): void {
        expect(fn (): Slug => new Slug($value))->toThrow(InvalidSlug::class);
    })->with(['', 'Acme', 'acme retail', 'acme--retail', '-acme', 'acme-', 'acme_retail']);

    it('derives a slug from free text', function (): void {
        expect(Slug::fromText('Acme Retail Group', 'fallback')->value)->toBe('acme-retail-group');
    });

    it('falls back when the text has no latin characters', function (): void {
        // Arabic client names are common here and can slugify to nothing.
        expect(Slug::fromText('شركة الاتصالات', 'client-12345678')->value)->toBe('client-12345678');
    });

    it('collapses punctuation and repeated separators', function (): void {
        expect(Slug::fromText('Acme   &&  Co. (2026)!', 'fallback')->value)->toBe('acme-co-2026');
    });
});

describe('Email', function (): void {
    it('normalises case and whitespace', function (): void {
        expect((new Email('  Buyer@Example.COM '))->value)->toBe('buyer@example.com');
    });

    it('rejects invalid addresses', function (string $value): void {
        expect(fn (): Email => new Email($value))->toThrow(InvalidEmail::class);
    })->with(['', 'not-an-email', 'a@', '@b.com', 'a b@example.com']);

    it('exposes the domain', function (): void {
        expect((new Email('buyer@smartcode.sa'))->domain())->toBe('smartcode.sa');
    });
});
