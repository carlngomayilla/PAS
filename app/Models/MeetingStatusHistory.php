<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit : trace de chaque changement de statut d'une reunion.
 */
class MeetingStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'meeting_report_id',
        'old_status',
        'new_status',
        'comment',
        'context',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_status' => MeetingStatus::class,
            'new_status' => MeetingStatus::class,
            'context' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(MeetingReport::class, 'meeting_report_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
