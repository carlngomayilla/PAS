<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pta extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_VALIDE = 'valide';
    public const STATUS_VERROUILLE = 'verrouille';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'exercice_id',
        'pao_id',
        'objectif_operationnel_id',
        'direction_id',
        'service_id',
        'titre',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exercice_id' => 'integer',
            'valide_le' => 'datetime',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function pao(): BelongsTo
    {
        return $this->belongsTo(Pao::class, 'pao_id');
    }

    public function objectifOperationnel(): BelongsTo
    {
        return $this->belongsTo(ObjectifOperationnel::class, 'objectif_operationnel_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'pta_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function scopeValidated(Builder $query): void
    {
        $query->where('statut', self::STATUS_VALIDE);
    }

    public function scopeLocked(Builder $query): void
    {
        $query->where('statut', self::STATUS_VERROUILLE);
    }

    public function scopeValidatedOrLocked(Builder $query): void
    {
        $query->whereIn('statut', [self::STATUS_VALIDE, self::STATUS_VERROUILLE]);
    }
}
