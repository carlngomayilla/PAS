<?php

namespace App\Models;

use App\Enums\MeetingApprovalDecision;
use App\Enums\MeetingApprovalLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visa pose sur une version de PV, par le SCIQ puis par la Planification.
 */
class MeetingApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_report_id',
        'approval_level',
        'decision',
        'comment',
        'reviewer_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'approval_level' => MeetingApprovalLevel::class,
            'decision' => MeetingApprovalDecision::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(MeetingReport::class, 'meeting_report_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
