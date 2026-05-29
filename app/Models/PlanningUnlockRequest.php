<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PlanningUnlockRequest extends Model
{
    use HasFactory;

    public const STATUS_SOUMISE = 'soumise';
    public const STATUS_APPROUVEE = 'approuvee';
    public const STATUS_REJETEE = 'rejetee';

    public const DECISION_APPROUVER = 'approuver';
    public const DECISION_REJETER = 'rejeter';

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
}
