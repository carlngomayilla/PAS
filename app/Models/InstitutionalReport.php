<?php

namespace App\Models;

use Database\Factories\InstitutionalReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InstitutionalReport extends Model
{
    /** @use HasFactory<InstitutionalReportFactory> */
    use HasFactory;

    public const TYPE_MEETING = 'meeting_minutes';

    public const TYPE_INCIDENT = 'incident_report';

    public const TYPE_ACTIVITY = 'activity_report';

    public const TYPE_OTHER = 'other';

    public const MEETING_TYPE_SERVICE = 'service';

    public const MEETING_TYPE_DIRECTION = 'direction';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED_SCIQ = 'submitted_sciq';

    public const STATUS_SUBMITTED_PLANNING = 'submitted_planning';

    public const STATUS_SUBMITTED_SCIQ_CHIEF = 'submitted_sciq_chief';

    public const STATUS_SUBMITTED_PLANNING_CHIEF = 'submitted_planning_chief';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_RETURNED = 'returned';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'report_type',
        'meeting_type',
        'title',
        'summary',
        'direction_id',
        'service_id',
        'responsible_id',
        'scheduled_at',
        'original_scheduled_at',
        'location',
        'participant_ids',
        'held_at',
        'postponed_at',
        'postponed_by',
        'postponement_reason',
        'postponement_count',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'actual_agenda',
        'decisions',
        'recommendations',
        'difficulties',
        'observations',
        'status',
        'submitted_by',
        'submitted_at',
        'minutes_published_at',
        'verified_at',
        'returned_at',
        'review_history',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'original_scheduled_at' => 'datetime',
            'participant_ids' => 'array',
            'held_at' => 'datetime',
            'postponed_at' => 'datetime',
            'postponement_count' => 'integer',
            'cancelled_at' => 'datetime',
            'submitted_at' => 'datetime',
            'minutes_published_at' => 'datetime',
            'verified_at' => 'datetime',
            'returned_at' => 'datetime',
            'review_history' => 'array',
        ];
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function postponedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'postponed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function justificatifs(): MorphMany
    {
        return $this->morphMany(Justificatif::class, 'justifiable');
    }

    public function meetingDecisions(): HasMany
    {
        return $this->hasMany(InstitutionalMeetingDecision::class);
    }
}
