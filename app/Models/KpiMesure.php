<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class KpiMesure extends Model
{
    use HasFactory;

    protected $table = 'kpi_mesures';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kpi_id',
        'periode',
        'valeur',
        'commentaire',
        'saisi_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valeur' => 'decimal:4',
        ];
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class, 'kpi_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function justificatifs(): MorphMany
    {
        return $this->morphMany(Justificatif::class, 'justifiable');
    }
}
