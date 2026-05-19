<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'direction_id',
        'code',
        'libelle',
        'type',
        'has_global_view',
        'has_global_write',
        'has_dual_interface',
        'is_control_unit',
        'is_operational',
        'actif',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'has_global_view' => 'boolean',
            'has_global_write' => 'boolean',
            'has_dual_interface' => 'boolean',
            'is_control_unit' => 'boolean',
            'is_operational' => 'boolean',
        ];
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'service_id');
    }

    public function ptas(): HasMany
    {
        return $this->hasMany(Pta::class, 'service_id');
    }
}
