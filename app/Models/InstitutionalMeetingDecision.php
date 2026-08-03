<?php

namespace App\Models;

use Database\Factories\InstitutionalMeetingDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionalMeetingDecision extends Model
{
    /** @use HasFactory<InstitutionalMeetingDecisionFactory> */
    use HasFactory;

    public const STATUS_TO_DO = 'to_do';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institutional_report_id',
        'description',
        'responsible_id',
        'priority',
        'due_at',
        'status',
        'follow_up_note',
        'created_by',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function institutionalReport(): BelongsTo
    {
        return $this->belongsTo(InstitutionalReport::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
