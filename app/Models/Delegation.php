<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Delegation extends Model
{
    use HasFactory;

    public const SCOPE_DIRECTION = 'direction';
    public const SCOPE_SERVICE = 'service';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'delegant_id',
        'delegue_id',
        'role_scope',
        'direction_id',
        'service_id',
        'permissions',
        'motif',
        'date_debut',
        'date_fin',
        'statut',
        'cree_par',
        'annule_par',
        'annule_le',
        'motif_annulation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'date_debut' => 'datetime',
            'date_fin' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public function delegant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegant_id');
    }

    public function delegue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    public function scopeActive(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('statut', 'active')
            ->where('date_debut', '<=', $at)
            ->where('date_fin', '>=', $at);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = collect($this->permissions ?? [])
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values();

        return $permissions->contains($permission);
    }

    /**
     * @return Collection<int, string>
     */
    public function permissionsCollection(): Collection
    {
        return collect($this->permissions ?? [])
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values();
    }

    public function isActiveAt(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->statut === 'active'
            && $this->date_debut !== null
            && $this->date_fin !== null
            && $this->date_debut->lte($at)
            && $this->date_fin->gte($at);
    }
}
