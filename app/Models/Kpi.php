<?php

namespace App\Models;

use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Kpi extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'libelle',
        'unite',
        'cible',
        'seuil_alerte',
        'periodicite',
        'est_a_renseigner',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'mode_saisie_label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cible' => 'decimal:4',
            'seuil_alerte' => 'decimal:4',
            'est_a_renseigner' => 'boolean',
        ];
    }

    public function getModeSaisieLabelAttribute(): string
    {
        return UiLabel::indicatorInputMode($this->est_a_renseigner);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }

    public function mesures(): HasMany
    {
        return $this->hasMany(KpiMesure::class, 'kpi_id');
    }

    public function justificatifs(): MorphMany
    {
        return $this->morphMany(Justificatif::class, 'justifiable');
    }

    public function scopeARenseigner(Builder $query): Builder
    {
        return $query->where('est_a_renseigner', true);
    }
}
