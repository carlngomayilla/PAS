<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DeletionRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLEMENT_REQUESTED = 'complement_requested';
    public const STATUS_CORRECTED = 'corrected';

    public const DECISION_DELETE = 'delete';
    public const DECISION_DISABLE = 'disable';
    public const DECISION_ARCHIVE = 'archive';
    public const DECISION_REJECT = 'reject';
    public const DECISION_REQUEST_COMPLEMENT = 'request_complement';
    public const DECISION_CORRECT = 'correct';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'requested_by',
        'reviewed_by',
        'module',
        'entity_type',
        'entity_id',
        'entity_label',
        'requested_action',
        'status',
        'reason',
        'reviewer_note',
        'impact_summary',
        'decision',
        'decided_at',
        'executed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'impact_summary' => 'array',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entity_id');
    }

    public function isPending(): bool
    {
        return in_array((string) $this->status, [self::STATUS_PENDING, self::STATUS_COMPLEMENT_REQUESTED], true);
    }
}
