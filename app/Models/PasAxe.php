<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PasAxe extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'pas_axes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pas_id',
        'direction_id',
        'code',
        'libelle',
        'periode_debut',
        'periode_fin',
        'description',
        'ordre',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (PasAxe $axe): void {
            $axe->objectifs()->get()->each->delete();
        });
    }

    public function pas(): BelongsTo
    {
        return $this->belongsTo(Pas::class, 'pas_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function objectifs(): HasMany
    {
        return $this->hasMany(PasObjectif::class, 'pas_axe_id')
            ->orderBy('ordre')
            ->orderBy('id');
    }
}
