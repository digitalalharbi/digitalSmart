<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| Sandbox fixture loading
|--------------------------------------------------------------------------
|
| The loader exists to do three things and nothing else: read, resolve relative
| dates, paginate. Every extra transformation here would be one the real adapter
| does not perform, which would make the fixtures a worse rehearsal than no
| fixtures at all.
|
*/

function fixtures(string $today = '2026-10-05'): SandboxFixtures
{
    return new SandboxFixtures(
        dirname(__DIR__, 4).'/database/fixtures/providers',
        new DateTimeImmutable($today),
    );
}

it('pages performance like a reporting endpoint, not two rows at a time', function (): void {
    $page = fixtures()->page(Provider::Meta, 'insights_ad');

    // Structure pages tightly to exercise cursors; insights page like the
    // real APIs, because paging a fortnight two rows at a time buys nothing.
    expect(fixtures()->pageSizeFor('insights_ad'))->toBe(SandboxFixtures::INSIGHT_PAGE_SIZE)
        ->and(fixtures()->pageSizeFor('campaigns'))->toBe(SandboxFixtures::PAGE_SIZE)
        ->and($page->items)->toHaveCount(SandboxFixtures::INSIGHT_PAGE_SIZE)
        ->and($page->hasMore())->toBeTrue();
});

it('pages with an opaque cursor, like every real provider', function (): void {
    $first = fixtures()->page(Provider::Meta, 'campaigns');

    expect($first->items)->toHaveCount(SandboxFixtures::PAGE_SIZE)
        ->and($first->hasMore())->toBeTrue()
        // Opaque: not the offset itself, so nothing can start treating it as a
        // number the way a bare "2" would invite.
        ->and($first->nextCursor)->not->toBe('2');

    $second = fixtures()->page(Provider::Meta, 'campaigns', $first->nextCursor);

    expect($second->items[0]['id'] ?? null)->not->toBe($first->items[0]['id'] ?? null);
});

it('walks every row across pages without repeating one', function (): void {
    $loader = fixtures();
    $seen = [];
    $cursor = null;

    do {
        $page = $loader->page(Provider::Meta, 'campaigns', $cursor);

        foreach ($page->items as $row) {
            $seen[] = $row['id'] ?? null;
        }

        $cursor = $page->nextCursor;
    } while ($cursor !== null);

    expect($seen)->toHaveCount(4)
        ->and(array_unique($seen))->toHaveCount(4);
});

it('rejects a cursor it did not issue', function (): void {
    // A corrupted or hand-written cursor must fail rather than silently
    // restarting, which would re-read a range and look like a successful sync.
    expect(fn (): mixed => fixtures()->page(Provider::Meta, 'campaigns', 'not-a-cursor'))
        ->toThrow(RuntimeException::class);
});

it('resolves relative dates so the sandbox never ages into irrelevance', function (): void {
    $rows = fixtures('2026-10-05')->rows(Provider::Meta, 'insights_ad');
    $dates = array_unique(array_map(static fn (array $row): mixed => $row['date_start'] ?? null, $rows));

    sort($dates);

    expect($dates)->toHaveCount(14)
        ->and($dates[0])->toBe('2026-09-21')
        ->and($dates[13])->toBe('2026-10-04');
});

it('moves the whole window when the clock moves', function (): void {
    $rows = fixtures('2026-12-25')->rows(Provider::Meta, 'insights_ad');

    expect($rows[0]['date_start'] ?? null)->toStartWith('2026-12-');
});

it('strips loader directives before a mapper can see them', function (): void {
    $row = fixtures()->rows(Provider::Meta, 'insights_ad')[0];

    // A mapper that saw `_day_offset` could come to depend on it, and the real
    // API has no such field.
    foreach (array_keys($row) as $key) {
        expect(str_starts_with($key, '_'))->toBeFalse();
    }
});

it('reports an absent fixture as absent rather than inventing a shape', function (): void {
    $loader = fixtures();

    // ChatGPT Ads publishes no contract, so there is nothing to put in a file.
    expect($loader->has(Provider::OpenAI, 'campaigns'))->toBeFalse()
        ->and($loader->rows(Provider::OpenAI, 'campaigns'))->toBe([])
        ->and($loader->page(Provider::OpenAI, 'campaigns')->items)->toBe([])
        ->and($loader->page(Provider::OpenAI, 'campaigns')->hasMore())->toBeFalse();
});

it('filters rows without paging past the ones it dropped', function (): void {
    $loader = fixtures();

    $page = $loader->page(
        Provider::Meta,
        'campaigns',
        null,
        static fn (array $row): bool => ($row['effective_status'] ?? null) === 'ACTIVE',
    );

    expect($page->items)->toHaveCount(2)
        ->and($page->hasMore())->toBeFalse();
});
