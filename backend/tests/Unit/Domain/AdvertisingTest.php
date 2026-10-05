<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\ProviderErrorCategory;
use App\Domain\Advertising\SyncKind;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Advertising\ValueObject\MetricSet;
use App\Domain\Advertising\ValueObject\Money;
use App\Domain\Integration\Credentials;
use App\Domain\Shared\Exception\CurrencyMismatch;

/*
|--------------------------------------------------------------------------
| Advertising domain
|--------------------------------------------------------------------------
|
| The rules that decide whether a number can be shown, added, or trusted. These
| are plain objects on purpose: if labelling a figure needed a database, it would
| end up being decided at the edge, which is where it gets forgotten.
|
*/

describe('money', function (): void {
    it('holds minor units so arithmetic never drifts', function (): void {
        // 0.1 + 0.2 in floats is not 0.3. Spend cannot be stored that way.
        $total = Money::of(10, 'SAR')->plus(Money::of(20, 'SAR'));

        expect($total->minorUnits)->toBe(30)
            ->and($total->toDecimal())->toBe('0.30');
    });

    it('formats as an exact decimal string rather than a float', function (): void {
        // The whole point of the class would be lost at the last step if this
        // returned a float: 12.339999999999999 is not a price.
        expect(Money::of(1_234, 'SAR')->toDecimal())->toBe('12.34')
            ->and(Money::of(5, 'SAR')->toDecimal())->toBe('0.05')
            ->and(Money::of(100_000, 'SAR')->toDecimal())->toBe('1000.00')
            ->and(Money::of(-250, 'SAR')->toDecimal())->toBe('-2.50')
            ->and(Money::of(0, 'SAR')->toDecimal())->toBe('0.00')
            // Currencies with no minor unit, such as JPY.
            ->and(Money::of(1_500, 'JPY')->toDecimal(0))->toBe('1500');
    });

    it('reads Google and X micros', function (): void {
        // Both report currency in millionths of a unit.
        expect(Money::fromMicros(1_250_000, 'USD')->minorUnits)->toBe(125)
            ->and(Money::fromMicros(1, 'USD')->minorUnits)->toBe(0);
    });

    it('reads Meta decimal strings without going through a float', function (): void {
        expect(Money::fromDecimalString('1234.56', 'SAR')->minorUnits)->toBe(123_456)
            ->and(Money::fromDecimalString('0.07', 'SAR')->minorUnits)->toBe(7)
            ->and(Money::fromDecimalString('90', 'SAR')->minorUnits)->toBe(9_000);
    });

    it('refuses to add two currencies', function (): void {
        // Silently summing SAR and USD is how a report becomes fiction.
        expect(fn (): Money => Money::of(100, 'SAR')->plus(Money::of(100, 'USD')))
            ->toThrow(CurrencyMismatch::class);
    });
});

describe('derived metrics', function (): void {
    it('returns null rather than dividing by a metric the provider withheld', function (): void {
        $set = new MetricSet(impressions: null, clicks: 40);

        // No impressions reported means CTR is unknown, not zero.
        expect($set->clickThroughRate())->toBeNull();
    });

    it('distinguishes a reported zero from an absent metric', function (): void {
        expect((new MetricSet(impressions: 0, clicks: 0))->clickThroughRate())->toBeNull()
            ->and((new MetricSet(impressions: 1_000, clicks: 0))->clickThroughRate())->toBe(0.0);
    });

    it('computes ROAS only when both sides share a currency', function (): void {
        $matched = new MetricSet(
            spend: Money::of(10_000, 'SAR'),
            revenue: Money::of(40_000, 'SAR'),
        );
        $mismatched = new MetricSet(
            spend: Money::of(10_000, 'SAR'),
            revenue: Money::of(40_000, 'USD'),
        );

        expect($matched->returnOnAdSpend())->toBe(4.0)
            // A cross-currency ratio is a number with no meaning, so there is
            // none to report.
            ->and($mismatched->returnOnAdSpend())->toBeNull();
    });
});

describe('metric sources', function (): void {
    it('knows which figures reflect money that actually moved', function (): void {
        expect(MetricSource::Live->isActual())->toBeTrue()
            ->and(MetricSource::Stale->isActual())->toBeTrue()
            ->and(MetricSource::Manual->isActual())->toBeTrue()
            ->and(MetricSource::Sandbox->isActual())->toBeFalse()
            ->and(MetricSource::Estimated->isActual())->toBeFalse();
    });

    it('requires a label on everything but a live reading', function (): void {
        foreach (MetricSource::cases() as $source) {
            expect($source->requiresLabel())->toBe($source !== MetricSource::Live);
        }
    });

    it('lets actuals combine but keeps projections and fixtures apart', function (): void {
        expect(MetricSource::Live->mixesWith(MetricSource::Stale))->toBeTrue()
            ->and(MetricSource::Manual->mixesWith(MetricSource::Synced))->toBeTrue()
            // A total that mixed a forecast with real spend would read as a
            // measurement and be impossible to unpick afterwards.
            ->and(MetricSource::Estimated->mixesWith(MetricSource::Live))->toBeFalse()
            ->and(MetricSource::Sandbox->mixesWith(MetricSource::Synced))->toBeFalse()
            ->and(MetricSource::Sandbox->mixesWith(MetricSource::Sandbox))->toBeTrue();
    });

    it('makes an aggregate carry its weakest input', function (): void {
        // One stale day makes the month stale. One sandbox row makes the figure
        // sandbox. The label travels with the number.
        expect(MetricSource::weakest([MetricSource::Live, MetricSource::Live]))->toBe(MetricSource::Live)
            ->and(MetricSource::weakest([MetricSource::Live, MetricSource::Stale]))->toBe(MetricSource::Stale)
            ->and(MetricSource::weakest([MetricSource::Synced, MetricSource::Partial]))->toBe(MetricSource::Partial)
            ->and(MetricSource::weakest([MetricSource::Live, MetricSource::Sandbox]))->toBe(MetricSource::Sandbox)
            ->and(MetricSource::weakest([MetricSource::Sandbox, MetricSource::Estimated]))->toBe(MetricSource::Estimated);
    });

    it('treats an empty aggregate as live rather than inventing doubt', function (): void {
        expect(MetricSource::weakest([]))->toBe(MetricSource::Live);
    });
});

describe('sandbox credentials', function (): void {
    it('treats a connection as sandbox only when every key is the token', function (): void {
        $keys = ['client_id', 'client_secret'];

        expect((new Credentials(['client_id' => 'sandbox', 'client_secret' => 'sandbox']))->isSandbox($keys))
            ->toBeTrue()
            /*
             * Half-filled is a configuration mistake, not a sandbox. Treating it
             * as one would hide the mistake and label real-looking output
             * "sandbox" — or worse, the other way round.
             */
            ->and((new Credentials(['client_id' => 'sandbox', 'client_secret' => 'real']))->isSandbox($keys))
            ->toBeFalse()
            ->and(Credentials::empty()->isSandbox($keys))->toBeFalse()
            // A provider with no required keys cannot be a sandbox by accident.
            ->and((new Credentials(['a' => 'sandbox']))->isSandbox([]))->toBeFalse();
    });
});

describe('entity levels', function (): void {
    it('knows its own parent', function (): void {
        expect(AdEntityType::Ad->parent())->toBe(AdEntityType::AdGroup)
            ->and(AdEntityType::AdGroup->parent())->toBe(AdEntityType::Campaign)
            ->and(AdEntityType::Campaign->parent())->toBe(AdEntityType::Account)
            ->and(AdEntityType::Account->parent())->toBeNull();
    });
});

describe('objectives', function (): void {
    it('names the metric each objective is actually judged on', function (): void {
        // A sales campaign judged on impressions, or an awareness campaign
        // judged on ROAS, is a report that answers the wrong question.
        expect(CampaignObjective::Sales->primaryMetric())->toBe('purchases')
            ->and(CampaignObjective::Leads->primaryMetric())->toBe('leads')
            ->and(CampaignObjective::Reach->primaryMetric())->toBe('reach')
            ->and(CampaignObjective::Traffic->primaryMetric())->toBe('link_clicks');
    });
});

describe('sync vocabulary', function (): void {
    it('reads structure in dependency order, ads last', function (): void {
        $order = SyncKind::structureOrder();

        expect($order)->toBe([
            SyncKind::Campaigns,
            SyncKind::AdGroups,
            SyncKind::Creatives,
            SyncKind::Ads,
        ])
            // An ad references both an ad group and a creative.
            ->and(array_search(SyncKind::Ads, $order, true))->toBe(3);
    });

    it('separates time-series kinds from paginated ones', function (): void {
        expect(SyncKind::Insights->isTimeSeries())->toBeTrue()
            ->and(SyncKind::Campaigns->isTimeSeries())->toBeFalse();
    });

    it('treats only a complete run as trustworthy', function (): void {
        expect(SyncStatus::Succeeded->isTrustworthy())->toBeTrue()
            // A partial run's rows are real but its totals understate reality.
            ->and(SyncStatus::Partial->isTrustworthy())->toBeFalse()
            ->and(SyncStatus::Partial->isFinished())->toBeTrue()
            ->and(SyncStatus::Running->isFinished())->toBeFalse();
    });

    it('sorts provider failures by who can fix them', function (): void {
        expect(ProviderErrorCategory::fromStatusCode(429))->toBe(ProviderErrorCategory::RateLimit)
            ->and(ProviderErrorCategory::fromStatusCode(401))->toBe(ProviderErrorCategory::Auth)
            ->and(ProviderErrorCategory::fromStatusCode(503))->toBe(ProviderErrorCategory::Server)
            ->and(ProviderErrorCategory::fromStatusCode(null))->toBe(ProviderErrorCategory::Transport)
            /*
             * Both are retryable, and both are conditions X actually publishes: a
             * cancelled request and a lock another writer is holding. Reported as
             * unknown they would have reached an operator as something to fix.
             */
            ->and(ProviderErrorCategory::fromStatusCode(408)->isTransient())->toBeTrue()
            ->and(ProviderErrorCategory::fromStatusCode(423)->isTransient())->toBeTrue()
            ->and(ProviderErrorCategory::fromStatusCode(418))->toBe(ProviderErrorCategory::Unknown);

        // Waiting fixes a rate limit; only a person fixes an expired token.
        expect(ProviderErrorCategory::RateLimit->isTransient())->toBeTrue()
            ->and(ProviderErrorCategory::RateLimit->needsOperatorAction())->toBeFalse()
            ->and(ProviderErrorCategory::Auth->isTransient())->toBeFalse()
            ->and(ProviderErrorCategory::Auth->needsOperatorAction())->toBeTrue();
    });
});
