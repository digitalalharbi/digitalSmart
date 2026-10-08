<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * The platform entity an approved request names, read out of its proposal.
 *
 * An approval's proposal is deliberately free-form: most approvals in this product describe things it cannot
 * carry out — a targeting change, a creative going live, a conversation with a client about a figure. So the
 * question "can this one be executed" is answered by **reading the proposal and refusing anything ambiguous**,
 * not by requiring every approval to look like an API call.
 *
 * `fromProposal()` returns null unless the proposal names a provider, an account and a campaign — and, for a
 * budget change, an amount in minor units. Null is the ordinary answer, and the surface says the request does not
 * name a platform campaign rather than offering a button that would guess at one.
 *
 * Guessing is the specific failure this class exists to prevent. A proposal saying "pause the retail campaign"
 * is not a campaign id, and a product that matched it by name would eventually pause a different campaign with a
 * similar name. The proposal has to say which.
 */
final readonly class WriteTarget
{
    private function __construct(
        public Provider $provider,
        public string $externalAccountId,
        public string $externalCampaignId,
        public ?int $dailyBudgetMinor,
        public ?string $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $proposal
     */
    public static function fromProposal(array $proposal, WriteOperation $operation): ?self
    {
        $provider = Provider::tryFrom(self::text($proposal, 'provider') ?? '');
        $account = self::text($proposal, 'external_account_id');
        $campaign = self::text($proposal, 'external_campaign_id');

        if ($provider === null || $account === null || $campaign === null) {
            return null;
        }

        $budget = self::integer($proposal, 'daily_budget_minor');

        if ($operation === WriteOperation::SetCampaignDailyBudget) {
            /*
             * A budget change with no amount is not a budget change. Refused rather than defaulted: there is no
             * sensible default for what a client spends, and the one somebody would reach for — the current
             * amount — would make the whole request a no-op that reported success.
             */
            if ($budget === null || $budget <= 0) {
                return null;
            }
        } else {
            /*
             * And a pause carrying an amount is a proposal describing two changes while asking approval for one.
             * The approval's fingerprint covers the whole proposal, so this is not a security hole — it is a
             * request nobody could read correctly, which is worse.
             */
            if ($budget !== null) {
                return null;
            }
        }

        return new self($provider, $account, $campaign, $budget, self::text($proposal, 'currency'));
    }

    /** @param  array<string, mixed>  $proposal */
    private static function text(array $proposal, string $key): ?string
    {
        $value = $proposal[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param  array<string, mixed>  $proposal */
    private static function integer(array $proposal, string $key): ?int
    {
        $value = $proposal[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        /* A numeric string is how a figure arrives through JSON from a form. Anything else is not a number. */
        return is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 ? (int) trim($value) : null;
    }
}
