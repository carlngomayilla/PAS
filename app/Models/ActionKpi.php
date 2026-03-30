<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionKpi extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'kpi_delai',
        'kpi_performance',
        'kpi_conformite',
        'kpi_qualite',
        'kpi_risque',
        'kpi_global',
        'progression_reelle',
        'progression_theorique',
        'statut_calcule',
        'derniere_evaluation_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kpi_delai' => 'decimal:2',
            'kpi_performance' => 'decimal:2',
            'kpi_conformite' => 'decimal:2',
            'kpi_qualite' => 'decimal:2',
            'kpi_risque' => 'decimal:2',
            'kpi_global' => 'decimal:2',
            'progression_reelle' => 'decimal:2',
            'progression_theorique' => 'decimal:2',
            'derniere_evaluation_at' => 'datetime',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }
}
