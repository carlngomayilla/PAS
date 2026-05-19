<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'action_week_id',
        'niveau',
        'type_evenement',
        'message',
        'details',
        'cible_role',
        'utilisateur_id',
        'lu',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'lu' => 'boolean',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(ActionWeek::class, 'action_week_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * @param Builder<ActionLog> $query
     * @return Builder<ActionLog>
     */
    public function scopeActiveAlert(Builder $query): Builder
    {
        return $query
            ->whereIn('niveau', ['warning', 'critical', 'urgence'])
            ->where(function (Builder $detailsQuery): void {
                $detailsQuery
                    ->whereNull('details->resolved')
                    ->orWhere('details->resolved', false);
            });
    }

    public function isActiveAlert(): bool
    {
        $details = is_array($this->details) ? $this->details : [];

        return in_array((string) $this->niveau, ['warning', 'critical', 'urgence'], true)
            && ($details['resolved'] ?? false) !== true;
    }
}
