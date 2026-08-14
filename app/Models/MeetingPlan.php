<?php

namespace App\Models;

use App\Enums\MeetingType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Objectif de reunions defini par le SCIQ pour une structure et un mois.
 */
class MeetingPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'direction_id',
        'service_id',
        'meeting_type',
        'year',
        'quarter',
        'month',
        'expected_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'month' => 'integer',
            'expected_count' => 'integer',
            'meeting_type' => MeetingType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MeetingPlan $plan): void {
            $type = $plan->meeting_type instanceof MeetingType
                ? $plan->meeting_type
                : MeetingType::from((string) $plan->meeting_type);
            $serviceId = $plan->service_id !== null ? (int) $plan->service_id : null;

            if (! $type->requiresService()) {
                $serviceId = null;
                $plan->service_id = null;
            }

            $plan->scope_key = self::scopeKey($type, (int) $plan->direction_id, $serviceId);
        });
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

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    /** Libelle de la structure concernee par l'objectif. */
    public function structureLabel(): string
    {
        return $this->service?->libelle
            ?? $this->direction?->libelle
            ?? 'Structure non renseignée';
    }

    /**
     * Trimestre deduit du mois : le SCIQ saisit par trimestre, on garde les
     * deux pour eviter tout recalcul a la lecture.
     */
    public static function quarterForMonth(int $month): int
    {
        return (int) ceil(max(1, min(12, $month)) / 3);
    }

    public static function scopeKey(MeetingType $type, int $directionId, ?int $serviceId): string
    {
        if ($directionId <= 0) {
            throw new InvalidArgumentException('La direction du plan de réunions est obligatoire.');
        }

        if ($type->requiresService() && ($serviceId === null || $serviceId <= 0)) {
            throw new InvalidArgumentException('Le service du plan de réunions est obligatoire.');
        }

        return $type->requiresService()
            ? 'service:'.(int) $serviceId
            : 'direction:'.$directionId;
    }

    /** @return list<int> */
    public static function monthsOfQuarter(int $quarter): array
    {
        $first = (max(1, min(4, $quarter)) - 1) * 3 + 1;

        return [$first, $first + 1, $first + 2];
    }
}
