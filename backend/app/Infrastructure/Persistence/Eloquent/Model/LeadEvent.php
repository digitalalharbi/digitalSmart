<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Leads\LeadStage;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened to a lead.
 *
 * Kept separately from the lead's current stage, because "when was this qualified, and by whom" is a
 * question about events. A table holding only the current stage makes a pipeline report possible and a
 * pipeline *review* impossible — and the review is where an agency finds out that half its leads sat
 * untouched for a week.
 */
final class LeadEvent extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'lead_id', 'actor_id', 'type', 'from_stage', 'to_stage', 'note', 'occurred_at',
    ];

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_stage' => LeadStage::class,
            'to_stage' => LeadStage::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
