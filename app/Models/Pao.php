<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pao extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_VALIDE = 'valide';
    public const STATUS_EN_COURS = 'en_cours';
    public const STATUS_CLOTURE = 'cloture';
    public const STATUS_ARCHIVE = 'archive';
    public const STATUS_VERROUILLE = 'verrouille';

    protected $table = 'paos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'statut' => self::STATUS_EN_COURS,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'exercice_id',
        'code',
        'pas_id',
        'pas_objectif_id',
        'direction_id',
        'service_id',
        'annee',
        'titre',
        'echeance',
        'objectif_operationnel',
        'resultats_attendus',
        'indicateurs_associes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'exercice_id' => 'integer',
            'echeance' => 'date',
            'valide_le' => 'datetime',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function pas(): BelongsTo
    {
        return $this->belongsTo(Pas::class, 'pas_id');
    }

    public function pasObjectif(): BelongsTo
    {
        return $this->belongsTo(PasObjectif::class, 'pas_objectif_id');
    }

    public function axes(): HasMany
    {
        return $this->hasMany(PaoAxe::class, 'pao_id');
    }

    public function objectifsOperationnels(): HasMany
    {
        return $this->hasMany(ObjectifOperationnel::class, 'pao_id');
    }

    public function ptas(): HasMany
    {
        return $this->hasMany(Pta::class, 'pao_id');
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

    public function scopeForYear(Builder $query, int $year): void
    {
        $query->where('annee', $year);
    }
}
