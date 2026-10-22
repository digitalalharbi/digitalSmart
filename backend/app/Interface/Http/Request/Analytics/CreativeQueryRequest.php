<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Analytics;

use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Analytics\CreativeVerdict;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for creatives.
 *
 * The scope and the period are the analytics endpoint's own — the same parameters, read by the same code — so "last 28
 * days for this client on Meta" means one thing on both. What is added is only what a creative has: the grain (the asset or
 * the ad that runs it), a format, a verdict, and a page. The page is bounded at a hundred: this read may span a whole agency.
 */
final class CreativeQueryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'start' => ['sometimes', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:start'],
            'preset' => ['sometimes', Rule::in(['7d', '14d', '28d', '30d', '90d', 'this_month', 'last_month'])],

            'providers' => ['sometimes', 'array'],
            'providers.*' => [Rule::in(Provider::values())],
            'account_ids' => ['sometimes', 'array'],
            'account_ids.*' => ['uuid'],
            'campaign_ids' => ['sometimes', 'array'],
            'campaign_ids.*' => ['uuid'],
            'ad_group_ids' => ['sometimes', 'array'],
            'ad_group_ids.*' => ['uuid'],
            'ad_ids' => ['sometimes', 'array'],
            'ad_ids.*' => ['uuid'],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => ['uuid'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],

            'grain' => ['sometimes', Rule::in(['asset', 'ad'])],
            'format' => ['sometimes', 'nullable', Rule::in(array_map(static fn (CreativeFormat $format): string => $format->value, CreativeFormat::cases()))],
            'verdict' => ['sometimes', 'nullable', Rule::in(array_map(static fn (CreativeVerdict $verdict): string => $verdict->value, CreativeVerdict::cases()))],
            'ad_status' => ['sometimes', 'nullable', Rule::in(array_map(static fn (EntityStatus $status): string => $status->value, EntityStatus::cases()))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * A bounded period. This read spans many clients and campaigns, so a range wider than the analytics layer's own limit is
     * refused rather than answered slowly.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('start');
            $end = $this->input('end');

            if (is_string($start) && is_string($end) && ! $validator->errors()->has('end')) {
                $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;

                if ($days > AnalyticsQueryRequest::MAX_RANGE_DAYS) {
                    $validator->errors()->add('end', 'That period is longer than '.AnalyticsQueryRequest::MAX_RANGE_DAYS.' days.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'end.after_or_equal' => 'The end date cannot fall before the start date.',
        ];
    }

    /** The period and the scope, parsed by the analytics request's own code so the two endpoints cannot read them differently. */
    public function analytics(Clock $clock): AnalyticsRequest
    {
        return AnalyticsQueryRequest::createFrom($this)->toAnalyticsRequest($clock);
    }

    public function grain(): string
    {
        return $this->string('grain', 'asset')->value() === 'ad' ? 'ad' : 'asset';
    }

    public function creativeFormat(): ?string
    {
        $value = $this->string('format')->value();

        return $value === '' ? null : $value;
    }

    /** The ad's own status — meaningful at the grain of an ad; an asset is not one ad and has none. */
    public function adStatus(): ?string
    {
        $value = $this->string('ad_status')->value();

        return $value === '' ? null : $value;
    }

    public function verdict(): ?string
    {
        $value = $this->string('verdict')->value();

        return $value === '' ? null : $value;
    }
}
