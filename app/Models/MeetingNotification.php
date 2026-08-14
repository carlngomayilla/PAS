<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification ciblee du module, adressee a un utilisateur concerne par la
 * structure de la reunion.
 */
class MeetingNotification extends Model
{
    use HasFactory;

    public const TYPE_PLAN_PUBLISHED = 'plan_published';

    public const TYPE_SCHEDULED = 'scheduled';

    public const TYPE_POSTPONED = 'postponed';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_REMINDER = 'reminder';

    public const TYPE_REPORT_EXPECTED = 'report_expected';

    public const TYPE_REPORT_SUBMITTED = 'report_submitted';

    public const TYPE_REPORT_AWAITING_PLANIFICATION = 'report_awaiting_planification';

    public const TYPE_CORRECTION_REQUESTED = 'correction_requested';

    public const TYPE_VALIDATED = 'validated';

    protected $fillable = [
        'meeting_id',
        'meeting_plan_id',
        'meeting_report_id',
        'user_id',
        'notification_type',
        'message',
        'read_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MeetingPlan::class, 'meeting_plan_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(MeetingReport::class, 'meeting_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
