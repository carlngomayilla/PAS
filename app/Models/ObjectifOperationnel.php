<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ObjectifOperationnel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'objectifs_operationnels';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pao_id',
        'code',
        'pas_id',
        'pas_axe_id',
        'pas_objectif_id',
        'direction_id',
        'service_id',
        'libelle',
        'description',
        'echeance',
        'indicateurs',
        'statut',
        'import_ordre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'echeance' => 'date',
            'import_ordre' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function pao(): BelongsTo
    {
        return $this->belongsTo(Pao::class, 'pao_id');
    }

    public function pas(): BelongsTo
    {
        return $this->belongsTo(Pas::class, 'pas_id');
    }

    public function pasAxe(): BelongsTo
    {
        return $this->belongsTo(PasAxe::class, 'pas_axe_id');
    }

    public function pasObjectif(): BelongsTo
    {
        return $this->belongsTo(PasObjectif::class, 'pas_objectif_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function ptas(): HasMany
    {
        return $this->hasMany(Pta::class, 'objectif_operationnel_id');
    }

    public function pta(): HasOne
    {
        return $this->hasOne(Pta::class, 'objectif_operationnel_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'objectif_operationnel_id');
    }

    public function scopeForService(Builder $query, int $serviceId): void
    {
        $query->where('service_id', $serviceId);
    }

    public static function ensureFromPao(Pao $pao, ?string $libelle = null): ?self
    {
        if (! $pao->exists
            || $pao->pas_id === null
            || $pao->pas_objectif_id === null
            || $pao->direction_id === null
            || $pao->service_id === null
        ) {
            return null;
        }

        $existing = self::query()
            ->where('pao_id', (int) $pao->id)
            ->where('service_id', (int) $pao->service_id)
            ->orderBy('id')
            ->first();

        if ($existing instanceof self) {
            return $existing;
        }

        $pasAxeId = DB::table('pas_objectifs')
            ->where('id', (int) $pao->pas_objectif_id)
            ->value('pas_axe_id');

        if ($pasAxeId === null) {
            return null;
        }

        $label = trim((string) ($libelle ?: $pao->objectif_operationnel ?: $pao->titre));
        if ($label === '') {
            $label = 'Objectif operationnel PAO #'.$pao->id;
        }

        $year = (int) ($pao->annee ?: now()->year);
        $echeance = $pao->echeance?->format('Y-m-d') ?: $year.'-12-31';

        return self::query()->create([
            'pao_id' => (int) $pao->id,
            'pas_id' => (int) $pao->pas_id,
            'pas_axe_id' => (int) $pasAxeId,
            'pas_objectif_id' => (int) $pao->pas_objectif_id,
            'direction_id' => (int) $pao->direction_id,
            'service_id' => (int) $pao->service_id,
            'libelle' => $label,
            'description' => $pao->resultats_attendus,
            'echeance' => $echeance,
            'indicateurs' => $pao->indicateurs_associes,
            'statut' => (string) ($pao->statut ?: 'brouillon'),
        ]);
    }
}
