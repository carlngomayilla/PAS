<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cible' => 'decimal:4',
            'seuil_alerte' => 'decimal:4',
        ];
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
}
