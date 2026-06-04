<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pas extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIF = 'actif';
    public const STATUS_CLOTURE = 'cloture';
    public const STATUS_ARCHIVE = 'archive';

    protected $table = 'pas';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'statut' => self::STATUS_ACTIF,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'exercice_id',
        'titre',
        'periode_debut',
        'periode_fin',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periode_debut' => 'integer',
            'periode_fin' => 'integer',
            'exercice_id' => 'integer',
            'valide_le' => 'datetime',
            'deleted_at' => 'datetime',
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

    public function axes(): HasMany
    {
        return $this->hasMany(PasAxe::class, 'pas_id');
    }

    public function paos(): HasMany
    {
        return $this->hasMany(Pao::class, 'pas_id');
    }

    public function directions(): BelongsToMany
    {
        return $this->belongsToMany(
            Direction::class,
            'pas_directions',
            'pas_id',
            'direction_id'
        )->withTimestamps();
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
