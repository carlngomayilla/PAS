<?php

namespace App\Models;

use Database\Factories\BudgetOverrunRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BudgetOverrunRequest extends Model
{
    /** @use HasFactory<BudgetOverrunRequestFactory> */
    use HasFactory;

    public const SCOPE_ACTION = 'action';

    public const SCOPE_SERVICE = 'service';

    public const SCOPE_DIRECTION = 'direction';

    public const STATUS_PENDING_DIRECTOR = 'pending_daf_director';

    public const STATUS_PENDING_DG = 'pending_dg';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'base_budget',
        'requested_extra',
        'status',
        'reason',
        'requested_by',
        'daf_director_id',
        'daf_director_reviewed_at',
        'daf_director_note',
        'dg_decided_by',
        'dg_decided_at',
        'dg_note',
    ];

    protected function casts(): array
    {
        return [
            'base_budget' => 'decimal:2',
            'requested_extra' => 'decimal:2',
            'daf_director_reviewed_at' => 'datetime',
            'dg_decided_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function dafDirector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'daf_director_id');
    }

    public function dgDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dg_decided_by');
    }

    public function justificatifs(): MorphMany
    {
        return $this->morphMany(Justificatif::class, 'justifiable');
    }
}
