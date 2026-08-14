<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Reunion programmee par un chef de service ou un directeur.
 */
class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_plan_id',
        'direction_id',
        'service_id',
        'meeting_type',
        'label',
        'location',
        'agenda',
        'participant_ids',
        'year',
        'quarter',
        'month',
        'original_scheduled_date',
        'current_scheduled_date',
        'scheduled_time',
        'held_at',
        'status',
        'is_extra',
        'was_postponed',
        'postponement_count',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'validated_at',
        'created_by',
        'responsible_id',
    ];

    protected function casts(): array
    {
        return [
            'meeting_type' => MeetingType::class,
            'status' => MeetingStatus::class,
            'year' => 'integer',
            'quarter' => 'integer',
            'month' => 'integer',
            'original_scheduled_date' => 'date',
            'current_scheduled_date' => 'date',
            'participant_ids' => 'array',
            'held_at' => 'datetime',
            'is_extra' => 'boolean',
            'was_postponed' => 'boolean',
            'postponement_count' => 'integer',
            'cancelled_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MeetingPlan::class, 'meeting_plan_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MeetingReport::class)->orderByDesc('version');
    }

    /** PV actif : la derniere version deposee. */
    public function currentReport(): HasOne
    {
        return $this->hasOne(MeetingReport::class)->latestOfMany('version');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(MeetingStatusHistory::class)->orderByDesc('changed_at');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MeetingNotification::class);
    }

    public function structureLabel(): string
    {
        return $this->service?->libelle
            ?? $this->direction?->libelle
            ?? 'Structure non renseignée';
    }

    /** La date de tenue est-elle passee ? */
    public function isDue(?Carbon $reference = null): bool
    {
        $reference = $reference?->copy() ?? Carbon::today();

        return $this->current_scheduled_date instanceof Carbon
            && $this->current_scheduled_date->copy()->startOfDay()->lte($reference->startOfDay());
    }

    public function scheduledAt(): ?Carbon
    {
        if (! $this->current_scheduled_date instanceof Carbon) {
            return null;
        }

        $scheduledAt = $this->current_scheduled_date->copy()->startOfDay();
        $time = trim((string) $this->scheduled_time);

        if ($time !== '') {
            [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
            $scheduledAt->setTime($hour, $minute);
        }

        return $scheduledAt;
    }

    public function hasOccurred(?Carbon $reference = null): bool
    {
        $scheduledAt = $this->scheduledAt();

        return $scheduledAt instanceof Carbon
            && $scheduledAt->lte($reference?->copy() ?? now());
    }

    /** Reunion echue sans PV depose : elle n'est pas justifiee. */
    public function isUnjustified(?Carbon $reference = null): bool
    {
        return $this->isDue($reference)
            && $this->status === MeetingStatus::PvAttendu;
    }

    public function isOfficiallyHeld(): bool
    {
        return $this->status->isOfficiallyHeld();
    }
}
