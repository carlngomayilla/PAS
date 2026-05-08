<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercice extends Model
{
    use HasFactory;

    public const STATUT_OUVERT = 'ouvert';
    public const STATUT_CLOS = 'clos';
    public const STATUT_ARCHIVE = 'archive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'annee',
        'libelle',
        'date_debut',
        'date_fin',
        'statut',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'date_debut' => 'date',
            'date_fin' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function paos(): HasMany
    {
        return $this->hasMany(Pao::class, 'exercice_id');
    }

    public function ptas(): HasMany
    {
        return $this->hasMany(Pta::class, 'exercice_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'exercice_id');
    }
}