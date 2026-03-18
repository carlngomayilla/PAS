<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaoAxe extends Model
{
    use HasFactory;

    protected $table = 'pao_axes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pao_id',
        'code',
        'libelle',
        'description',
        'ordre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
        ];
    }

    public function pao(): BelongsTo
    {
        return $this->belongsTo(Pao::class, 'pao_id');
    }

    public function objectifsStrategiques(): HasMany
    {
        return $this->hasMany(PaoObjectifStrategique::class, 'pao_axe_id');
    }
}
