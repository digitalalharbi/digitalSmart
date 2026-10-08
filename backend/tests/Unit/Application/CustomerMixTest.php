<?php

declare(strict_types=1);

use App\Application\Commerce\DTO\CustomerMix;

/*
|--------------------------------------------------------------------------
| The new-customer share, and when there is not one
|--------------------------------------------------------------------------
|
| The arithmetic is trivial; the refusals are the feature. A share computed over a denominator that is
| substantially "we cannot tell" is a figure somebody puts in a report, and a store's history in this product
| begins when its integration was connected — so on a new integration almost everybody is unclassifiable.
|
*/

/** Named for the thing rather than called `mix`, which is one of Laravel's own global helpers. */
function customerMix(int $new, int $returning, int $unknown): CustomerMix
{
    return new CustomerMix(
        newCustomers: $new > 0 ? 1 : 0,
        returningCustomers: $returning > 0 ? 1 : 0,
        unknownCustomers: $unknown > 0 ? 1 : 0,
        newRevenueMinor: $new,
        returningRevenueMinor: $returning,
        unknownRevenueMinor: $unknown,
    );
}

it('reports a share while the unknown bucket is small', function (): void {
    // 10,000 of 100,000 unclassified: a tenth, which moves the answer by less than it is read at.
    expect(customerMix(50_000, 40_000, 10_000)->newShare())->toBe(0.5);
    expect(customerMix(50_000, 40_000, 10_000)->unavailableReason())->toBeNull();
});

it('withholds the share at exactly a fifth, not only beyond it', function (): void {
    /*
     * The boundary, pinned deliberately, because a reader will ask. "A fifth of this figure is guesswork" is
     * already not a figure to put in front of a client, so the ceiling withholds from the fifth onwards.
     */
    $atCeiling = customerMix(50_000, 30_000, 20_000);

    expect($atCeiling->unknownRevenueMinor / $atCeiling->totalRevenueMinor())->toBe(0.2);
    expect($atCeiling->newShare())->toBeNull();
    expect($atCeiling->unavailableReason())->toContain('before this store was connected');
});

it('gives two different reasons for having no share', function (): void {
    /*
     * Nothing sold, and too much unclassified, are different facts. A single "not available" would leave a reader
     * unable to tell a quiet month from a young integration — and only one of those gets better on its own.
     */
    expect(customerMix(0, 0, 0)->unavailableReason())->toContain('Nothing was sold');
    expect(customerMix(10_000, 0, 90_000)->unavailableReason())->toContain('before this store was connected');
});

it('never reports nought per cent for a period with no sales', function (): void {
    // Zero is a measurement. Null is the absence of one, and a revenue report must not confuse them.
    expect(customerMix(0, 0, 0)->newShare())->toBeNull();
    expect(customerMix(0, 0, 0)->totalCustomers())->toBe(0);
});

it('counts every bucket in its totals, including the one it cannot classify', function (): void {
    $mix = customerMix(50_000, 30_000, 20_000);

    /*
     * The unknown bucket is part of the period's revenue, not excluded from it. Leaving it out of the denominator
     * would make the other two shares add to 100% while describing four fifths of the money.
     */
    expect($mix->totalRevenueMinor())->toBe(100_000);
    expect($mix->totalCustomers())->toBe(3);
});
