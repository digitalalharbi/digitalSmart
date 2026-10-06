<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Model;

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use App\Infrastructure\Persistence\Eloquent\Concern\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who did something, and what happened next.
 *
 * Never created from a counted signal. A click is not a lead, and the absence of a `whatsapp_click` source
 * kind is what makes that true rather than merely intended.
 */
final class Lead extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'client_id', 'project_id', 'source_kind', 'source_detail',
        'store_order_id', 'measurement_source_id', 'external_id',
        'name', 'email', 'phone', 'email_key', 'phone_key', 'message',
        'stage', 'disqualified_reason', 'owner_id', 'value_minor', 'currency',
        'attribution_evidence', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term', 'click_identifier', 'landing_page', 'referrer',
        'received_at', 'first_contacted_at', 'qualified_at', 'closed_at',
    ];

    /**
     * Contact details are never serialised by accident.
     *
     * They belong to the agency's client's prospect, and a client-portal response must not carry them. This
     * is the belt; the braces are that no resource includes them.
     *
     * @var list<string>
     */
    protected $hidden = ['email', 'phone', 'email_key', 'phone_key'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<LeadEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class, 'lead_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_kind' => LeadSourceKind::class,
            'stage' => LeadStage::class,
            'attribution_evidence' => AttributionEvidence::class,
            'value_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'first_contacted_at' => 'immutable_datetime',
            'qualified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
