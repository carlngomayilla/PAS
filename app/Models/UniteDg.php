<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une unité de la Direction Générale (SCIQ, DGA, Cabinet, UCAS).
 * Chaque unité a un chef d'unité et un périmètre fonctionnel (global ou limité).
 */
class UniteDg extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'unites_dg';

    public const CODE_SCIQ = 'SCIQ';
    public const CODE_DGA = 'DGA';
    public const CODE_CABINET = 'CABINET';
    public const CODE_UCAS = 'UCAS';

    /** @var list<string> */
    protected $fillable = [
        'direction_id',
        'code',
        'libelle',
        'chef_user_id',
        'portee_globale',
        'actif',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'portee_globale' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function chef(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chef_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'unite_dg_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'unite_dg_id');
    }

    public function isGlobalScope(): bool
    {
        return (bool) $this->portee_globale;
    }
}
