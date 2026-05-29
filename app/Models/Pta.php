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

    // STATUS_BROUILLON : etat initial apres import Excel ou creation. Le PTA
    // existe en BDD mais ses actions doivent etre parametrees individuellement
    // par le chef de service avant que le PTA soit considere comme "enregistre"
    // (transition automatique vers STATUS_EN_COURS quand toutes les actions ont
    // statut_parametrage = 'parametre').
    public const STATUS_BROUILLON = 'brouillon';
    public const STATUS_EN_COURS = 'en_cours';
    public const STATUS_CLOTURE = 'cloture';
    public const STATUS_ARCHIVE = 'archive';
    public const STATUS_VALIDE = 'valide';
    public const STATUS_VERROUILLE = 'verrouille';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'exercice_id',
        'code',
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
            'modification_locked_at' => 'datetime',
            'modification_locked_by' => 'integer',
            'modification_unlocked_at' => 'datetime',
            'modification_unlocked_by' => 'integer',
            'modification_unlock_expires_at' => 'datetime',
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
