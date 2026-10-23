<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\Dispatch\ConsentPolicy;
use App\Domain\Tracking\Dispatch\ConversionSupport;
use App\Domain\Tracking\Dispatch\ProviderConversionCatalogue;
use App\Domain\Tracking\Dispatch\SkipReason;
use App\Domain\Tracking\TrafficClass;

it('maps only to what a provider’s own documentation allows', function (): void {
    expect(ProviderConversionCatalogue::refusal(Provider::Snapchat, 'SIGN_UP'))->toBeNull()
        ->and(ProviderConversionCatalogue::refusal(Provider::Snapchat, 'LEAD'))->toContain('SIGN_UP')
        ->and(ProviderConversionCatalogue::refusal(Provider::GoogleAds, 'customers/123/conversionActions/456'))->toBeNull()
        ->and(ProviderConversionCatalogue::refusal(Provider::GoogleAds, 'Lead form'))->toContain('conversionActions')
        ->and(ProviderConversionCatalogue::refusal(Provider::Meta, 'Lead'))->toBeNull()
        ->and(ProviderConversionCatalogue::refusal(Provider::Meta, 'bad name!'))->not->toBeNull();
});

it('refuses a provider nobody has read the documentation of, and one with no path', function (): void {
    expect(ProviderConversionCatalogue::for(Provider::TikTok)['support'])->toBe(ConversionSupport::Unverified)
        ->and(ProviderConversionCatalogue::for(Provider::LinkedIn)['support'])->toBe(ConversionSupport::Unverified)
        ->and(ProviderConversionCatalogue::for(Provider::X)['support'])->toBe(ConversionSupport::Unsupported)
        ->and(ProviderConversionCatalogue::for(Provider::OpenAI)['support'])->toBe(ConversionSupport::Unsupported)
        ->and(ProviderConversionCatalogue::refusal(Provider::TikTok, 'SubmitForm'))->toContain('TikTok')
        ->and(ProviderConversionCatalogue::refusal(Provider::X, 'Lead'))->not->toBeNull();

    foreach ([Provider::Meta, Provider::Snapchat, Provider::GoogleAds] as $p) {
        expect(ProviderConversionCatalogue::for($p)['source'])->toStartWith('https://');
    }
});

it('says Google’s upload path may be refused for a new token', function (): void {
    expect(ProviderConversionCatalogue::for(Provider::GoogleAds)['caveat'])->toContain('Data Manager API')
        ->and(ProviderConversionCatalogue::for(Provider::GoogleAds)['needs_click_id'])->toBe(['gclid', 'gbraid', 'wbraid']);
});

it('applies the consent policy the project chose, and never sends a denial', function (): void {
    expect(ConsentPolicy::Required->allows(ConsentState::Granted))->toBeTrue()
        ->and(ConsentPolicy::Required->allows(ConsentState::Unknown))->toBeFalse()
        ->and(ConsentPolicy::Required->allows(ConsentState::Denied))->toBeFalse()
        ->and(ConsentPolicy::NotRequired->allows(ConsentState::Unknown))->toBeTrue()
        ->and(ConsentPolicy::NotRequired->allows(ConsentState::Denied))->toBeFalse();
});

it('puts the gates in an order that names the most fundamental reason', function (): void {
    $granted = ConsentState::Granted;
    $ids = ['gclid' => 'x'];

    expect(SkipReason::gate(TrafficClass::Test, ConsentState::Denied, ConsentPolicy::Required, ['gclid'], []))->toBe(SkipReason::TestTraffic)
        ->and(SkipReason::gate(TrafficClass::SuspectedBot, $granted, ConsentPolicy::Required, [], []))->toBe(SkipReason::SuspectedBot)
        ->and(SkipReason::gate(TrafficClass::Valid, ConsentState::Unknown, ConsentPolicy::Required, ['gclid'], []))->toBe(SkipReason::Consent)
        ->and(SkipReason::gate(TrafficClass::Valid, $granted, ConsentPolicy::Required, ['gclid', 'gbraid'], []))->toBe(SkipReason::NoClickId)
        ->and(SkipReason::gate(TrafficClass::Valid, $granted, ConsentPolicy::Required, ['gclid', 'gbraid'], $ids))->toBeNull()
        ->and(SkipReason::gate(TrafficClass::Valid, $granted, ConsentPolicy::Required, [], []))->toBeNull();
});
