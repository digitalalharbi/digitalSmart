<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Budget\AllocationLevel;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetAllocation extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'budget_id', 'parent_id', 'level', 'provider',
        'external_account_id', 'campaign_id', 'ad_group_id', 'label',
        'amount_minor', 'position',
    ];

    /** @return BelongsTo<Budget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<AdGroup, $this> */
    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(AdGroup::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => AllocationLevel::class,
            'provider' => Provider::class,
            'amount_minor' => 'integer',
            'position' => 'integer',
        ];
    }
}
