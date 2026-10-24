<?php

declare(strict_types=1);

use App\Domain\Integration\Capability\CapabilityKey;
use App\Domain\Integration\Capability\CapabilityState;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\WriteSupport;

/*
| The registry is evidence, not opinion: every documented cell names the page it was read on, nothing unread is allowed to look
| documented, and the platforms whose new campaigns deliver by default are named.
*/

it('has a state for every key on every advertising platform', function (): void {
    $providers = array_map(static fn (Provider $p): string => $p->value, ProviderCapabilityRegistry::providers());

    expect($providers)->toEqualCanonicalizing(['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin', 'x', 'openai']);

    foreach (ProviderCapabilityRegistry::providers() as $provider) {
        foreach (CapabilityKey::cases() as $key) {
            expect(ProviderCapabilityRegistry::evidence($provider, $key)->state)->toBeInstanceOf(CapabilityState::class);
        }
    }
});

it('never calls anything documented without the page it was read on, and allows an attempt only on documented', function (): void {
    foreach (ProviderCapabilityRegistry::providers() as $provider) {
        foreach (CapabilityKey::cases() as $key) {
            $e = ProviderCapabilityRegistry::evidence($provider, $key);

            if ($e->state === CapabilityState::Documented) {
                expect($e->reference)->not->toBeNull("{$provider->value} {$key->value}")
                    ->and($e->verifiedOn)->not->toBeNull();
            }

            expect($e->state->allowsAttempt())->toBe($e->state === CapabilityState::Documented);
        }
    }
});

it('takes status and budget evidence from the write table the product already acts on', function (): void {
    foreach ([Provider::Meta, Provider::GoogleAds, Provider::TikTok, Provider::Snapchat, Provider::LinkedIn, Provider::X] as $provider) {
        foreach (CapabilityKey::cases() as $key) {
            $operation = $key->writeOperation();

            if ($operation === null) {
                continue;
            }

            $documented = ProviderEntityWrites::support($provider, $operation) === WriteSupport::Documented;
            expect(ProviderCapabilityRegistry::evidence($provider, $key)->state === CapabilityState::Documented)->toBe($documented, "{$provider->value} {$key->value}");
        }
    }

    // X documents no way to change a promoted tweet: unsupported, not merely unread.
    expect(ProviderCapabilityRegistry::evidence(Provider::X, CapabilityKey::AdPause)->state)->toBe(CapabilityState::Unsupported);
});

it('names the platforms whose new campaigns deliver unless told otherwise, and how each is created paused', function (): void {
    $delivering = array_values(array_map(
        static fn (Provider $p): string => $p->value,
        array_filter(ProviderCapabilityRegistry::providers(), static fn (Provider $p): bool => ProviderCapabilityRegistry::profile($p)['delivers_by_default'] ?? false),
    ));

    expect($delivering)->toEqualCanonicalizing(['tiktok', 'x'])
        ->and(ProviderCapabilityRegistry::profile(Provider::TikTok)['paused'] ?? '')->toContain('operation_status=DISABLE');

    foreach (ProviderCapabilityRegistry::providers() as $provider) {
        expect(ProviderCapabilityRegistry::profile($provider)['access'] ?? '')->not->toBe('');
    }
});
