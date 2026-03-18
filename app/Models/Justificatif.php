<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;

class Justificatif extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'justifiable_type',
        'justifiable_id',
        'action_week_id',
        'categorie',
        'nom_original',
        'chemin_stockage',
        'est_chiffre',
        'mime_type',
        'taille_octets',
        'description',
        'ajoute_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'est_chiffre' => 'boolean',
        ];
    }

    public function justifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function ajoutePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajoute_par');
    }

    public function actionWeek(): BelongsTo
    {
        return $this->belongsTo(ActionWeek::class, 'action_week_id');
    }
}
