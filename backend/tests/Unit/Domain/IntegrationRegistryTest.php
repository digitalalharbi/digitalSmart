<?php

declare(strict_types=1);

use App\Domain\Integration\AuthMethod;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderApiStatus;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Integration\ProviderEntity;

/*
|--------------------------------------------------------------------------
| The provider registry
|--------------------------------------------------------------------------
|
| One canonical catalogue of every platform: what it is called, what kind of thing it is,
| what its API can do, how it is authorised, how often it may be called. These tests exist
| because the registry is only worth having if it cannot quietly go stale or start claiming
| things the provider does not support.
|
*/

it('answers every question for every provider', function (): void {
    foreach (Provider::cases() as $provider) {
        expect($provider->label())->not->toBe('')
            ->and($provider->category())->toBeInstanceOf(ProviderCategory::class)
            ->and($provider->apiStatus())->toBeInstanceOf(ProviderApiStatus::class)
            ->and($provider->authMethod())->toBeInstanceOf(AuthMethod::class)
            // A provider with no credential keys could never be marked configured.
            ->and($provider->requiredCredentialKeys())->not->toBeEmpty();
    }
});

it('documents every provider it claims an API for', function (): void {
    foreach (Provider::cases() as $provider) {
        if ($provider->apiStatus() === ProviderApiStatus::Unavailable) {
            /*
             * Nothing to link to, nothing to claim. ChatGPT Ads is the whole reason this
             * status exists.
             */
            expect($provider->capabilities())->toBe([])
                ->and($provider->supportedEntities())->toBe([])
                ->and($provider->documentationUrl())->toBeNull();

            continue;
        }

        // A capability nobody can look up is a capability nobody can check.
        expect($provider->documentationUrl())->not->toBeNull()
            ->and($provider->capabilities())->not->toBeEmpty()
            ->and($provider->supportedEntities())->not->toBeEmpty();
    }
});

it('never claims a capability belonging to another kind of provider', function (): void {
    foreach (Provider::cases() as $provider) {
        foreach ($provider->capabilities() as $capability) {
            $category = $capability->category();

            if ($category === null) {
                /*
                 * Shared: discovering accounts, reading reporting figures and receiving
                 * webhooks are things every kind of provider does.
                 */
                continue;
            }

            /*
             * Anything else has to match. A store claiming `campaign_discovery` would be
             * offered as an allocation target and its orders compared with an advertising
             * budget.
             */
            expect($category)->toBe($provider->category());
        }
    }
});

it('only lists entities a provider of that kind could have', function (): void {
    $commerceOnly = [ProviderEntity::Order, ProviderEntity::Product, ProviderEntity::Customer];

    foreach (Provider::cases() as $provider) {
        if ($provider->category() === ProviderCategory::Commerce) {
            continue;
        }

        foreach ($commerceOnly as $entity) {
            // An ad platform with orders would make store revenue and platform-reported
            // revenue the same column.
            expect($provider->supportedEntities())->not->toContain($entity);
        }
    }
});

it('declares no write capability for the read-only integrations', function (): void {
    /*
     * Analytics and Tag Manager both publish write APIs. This product does not use them:
     * changing somebody's measurement setup from a reporting tool is a hazard, not a
     * feature, and the registry is where that decision is visible.
     */
    foreach ([Provider::GoogleAnalytics, Provider::GoogleTagManager] as $provider) {
        foreach ($provider->capabilities() as $capability) {
            expect($capability->isWrite())->toBeFalse();
        }
    }
});

/*
 * The converse — "every provider with a sync adapter declares a metrics API" — lives in
 * tests/Architecture/IntegrationBoundariesTest.php, which has the container these unit
 * tests deliberately do not. It is asserted in one place, not two.
 */

it('groups providers by category without losing any', function (): void {
    $grouped = 0;

    foreach (ProviderCategory::cases() as $category) {
        $grouped += count(Provider::inCategory($category));
    }

    expect($grouped)->toBe(count(Provider::cases()));
});

it('refuses to total figures across categories', function (): void {
    /*
     * The rule this whole taxonomy exists for: an ad platform's claimed revenue, an
     * analytics property's recorded revenue and a store's invoiced revenue are three
     * different numbers.
     */
    expect(ProviderCategory::Advertising->totalsWith(ProviderCategory::Advertising))->toBeTrue()
        ->and(ProviderCategory::Advertising->totalsWith(ProviderCategory::Commerce))->toBeFalse()
        ->and(ProviderCategory::Analytics->totalsWith(ProviderCategory::Commerce))->toBeFalse();
});

it('publishes a rate limit only where the provider published one', function (): void {
    // Tag Manager's is the tightest and the most surprising: per Google Cloud project, so
    // every workspace on one deployment shares it.
    $gtm = Provider::GoogleTagManager->rateLimit();

    expect($gtm?->requests)->toBe(25)
        ->and($gtm?->windowSeconds)->toBe(100)
        ->and($gtm?->scope)->toBe('project')
        ->and($gtm?->dailyRequests)->toBe(10_000);

    // Salla publishes none. Said so rather than guessed at.
    expect(Provider::Salla->rateLimit()?->requests)->toBeNull()
        ->and(Provider::Salla->rateLimit()?->isDocumented())->toBeTrue()
        ->and(Provider::OpenAI->rateLimit())->toBeNull();
});
