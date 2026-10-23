<?php

declare(strict_types=1);

use App\Domain\Tracking\BotHeuristic;
use App\Domain\Tracking\ClickIdCatalogue;
use App\Domain\Tracking\DestinationUrl;
use App\Domain\Tracking\Exception\UnsafeDestination;
use App\Domain\Tracking\LinkTemplate;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;

it('accepts a public web page and refuses everything a redirect must never reach', function (string $url): void {
    expect(fn () => DestinationUrl::parse($url, ['app.digitalsmart.test']))->toThrow(UnsafeDestination::class);
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<script>1</script>',
    'ftp' => 'ftp://example.com/file',
    'credentials' => 'https://user:pass@example.com/',
    'localhost' => 'http://localhost:8000/admin',
    'loopback' => 'http://127.0.0.1/',
    'private' => 'http://10.0.0.5/',
    'metadata' => 'http://169.254.169.254/latest/meta-data',
    'single label' => 'http://intranet/',
    'obfuscated' => 'http://2130706433/',
    'itself' => 'https://app.digitalsmart.test/login',
    'spaces' => 'https://example.com/a b',
    'schemeless' => '//evil.example/',
]);

it('adds parameters to a destination without overriding the ones the page already has', function (): void {
    $d = DestinationUrl::parse('https://shop.example.com/offer?utm_source=keep#top');

    expect($d->with(['utm_source' => 'x', 'utm_medium' => 'paid']))
        ->toBe('https://shop.example.com/offer?utm_source=keep&utm_medium=paid#top')
        ->and($d->with([]))->toBe('https://shop.example.com/offer?utm_source=keep#top');
});

it('keeps only the click identifiers a provider documents', function (): void {
    expect(ClickIdCatalogue::from([
        'gclid' => 'Cj0KCQ', 'GBRAID' => 'abc', 'fbclid' => 'IwAR1', 'ttclid' => 't1', 'ScCid' => 's1', 'li_fat_id' => 'l1', 'twclid' => 'x1',
        'madeup' => 'nope', 'utm_source' => 'x',
    ]))->toBe(['gclid' => 'Cj0KCQ', 'gbraid' => 'abc', 'fbclid' => 'IwAR1', 'ttclid' => 't1', 'sccid' => 's1', 'li_fat_id' => 'l1', 'twclid' => 'x1']);

    expect(ClickIdCatalogue::from(['gclid' => '<script>', 'fbclid' => str_repeat('a', 600), 'ttclid' => '']))->toBe([]);
});

it('builds campaign parameters from a template and leaves a typo visible', function (): void {
    expect(LinkTemplate::render(
        ['utm_source' => '{{platform}}', 'utm_campaign' => '{{campaign}}', 'utm_content' => '{{creative}}', 'utm_term' => '{{typo}}'],
        ['platform' => 'Snapchat', 'campaign' => 'Ramadan Offer', 'creative' => null],
    ))->toBe(['utm_source' => 'snapchat', 'utm_campaign' => 'ramadan_offer', 'utm_term' => '{{typo}}']);
});

it('marks likely programs and never calls the result certain', function (): void {
    expect(BotHeuristic::classify(null))->toBe(TrafficClass::SuspectedBot)
        ->and(BotHeuristic::classify('facebookexternalhit/1.1'))->toBe(TrafficClass::SuspectedBot)
        ->and(BotHeuristic::classify('python-requests/2.31'))->toBe(TrafficClass::SuspectedBot)
        ->and(BotHeuristic::classify('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'))
        ->toBe(TrafficClass::Valid)
        ->and(BotHeuristic::deviceClass('Mozilla/5.0 (iPhone; ...) Mobile'))->toBe('mobile')
        ->and(BotHeuristic::browserClass('Mozilla/5.0 Chrome/120 Safari/537'))->toBe('chrome');
});

it('lets a browser report only behaviour, never an outcome', function (): void {
    foreach ([TrackingEventName::LeadCreated, TrackingEventName::SaleCompleted, TrackingEventName::FormSubmit, TrackingEventName::LinkClick] as $server) {
        expect($server->isBrowserReportable())->toBeFalse();
    }

    expect(TrackingEventName::browserValues())->toContain('landing_view', 'form_start', 'whatsapp_click')
        ->and(TrackingEventName::WhatsAppClick->isConversionLike())->toBeFalse();
});

it('has no source for a conversation started in WhatsApp and no implementation of call tracking', function (): void {
    expect(TrackingEventName::browserValues())->not->toContain('whatsapp_conversation_started')
        ->and(TrackingEventName::WhatsAppConversationStarted->isBrowserReportable())->toBeFalse()
        ->and(TrackingEventName::WhatsAppClick->isConversionLike())->toBeFalse()
        ->and(class_exists(App\Infrastructure\Tracking\CallTracking::class))->toBeFalse();

    $answered = new App\Domain\Tracking\CallEvidence('provider', 'c1', new DateTimeImmutable('2026-10-01'), 40, true, null, null);
    $hangUp = new App\Domain\Tracking\CallEvidence('provider', 'c2', new DateTimeImmutable('2026-10-01'), 0, true, null, null);

    expect($answered->wasAnswered())->toBeTrue()->and($hangUp->wasAnswered())->toBeFalse();
});
