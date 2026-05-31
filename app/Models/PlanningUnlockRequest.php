<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PlanningUnlockRequest extends Model
{
    use HasFactory;

    // Circuit V2 : soumise (attente directeur) → transmise (directeur a transféré,
    // attente avis planif + décision DG) → approuvee | rejetee (décision DG).
    public const STATUS_SOUMISE = 'soumise';
    public const STATUS_TRANSMISE = 'transmise';
    public const STATUS_APPROUVEE = 'approuvee';
    public const STATUS_REJETEE = 'rejetee';

    public const DECISION_APPROUVER = 'approuver';
    public const DECISION_REJETER = 'rejeter';

    public const AVIS_FAVORABLE = 'favorable';
    public const AVIS_DEFAVORABLE = 'defavorable';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'module',
        'target_type',
        'target_id',
        'target_label',
        'direction_id',
        'service_id',
        'requested_by',
        // Circuit V2 — étape directeur
        'transferred_by',
        'transferred_at',
        'transfer_comment',
        // Circuit V2 — avis planification (consultatif)
        'planif_avis',
        'planif_avis_by',
        'planif_avis_at',
        'planif_comment',
        'justificatif_path',
        'reason',
        'status',
        'decision',
        'review_comment',
        'reviewed_by',
        'reviewed_at',
        'unlocked_until',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction_id' => 'integer',
            'service_id' => 'integer',
            'requested_by' => 'integer',
            'transferred_by' => 'integer',
            'transferred_at' => 'datetime',
            'planif_avis_by' => 'integer',
            'planif_avis_at' => 'datetime',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'unlocked_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function planifReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planif_avis_by');
    }
}
