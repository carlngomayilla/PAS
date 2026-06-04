<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;
use App\Services\Analytics\AnalyticsCacheVersionService;
use Illuminate\Support\Facades\Cache;

class Justificatif extends Model
{
    use HasFactory;

    private const EXECUTION_CATEGORIES = [
        'hebdomadaire',
        'final',
        'execution_quantitative',
        'execution_non_quantitative',
        'execution_mixte',
        'sous_action',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'justifiable_type',
        'justifiable_id',
        'action_week_id',
        'sous_action_id',
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

    protected static function booted(): void
    {
        static::created(function (Justificatif $justificatif): void {
            $justificatif->resolveMissingExecutionJustificatifAlert();
        });
    }

    public function justifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function ajoutePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajoute_par');
    }

    public function getActionWeekAttribute(): null
    {
        return null;
    }

    public function sousAction(): BelongsTo
    {
        return $this->belongsTo(SousAction::class, 'sous_action_id');
    }

    private function resolveMissingExecutionJustificatifAlert(): void
    {
        if ((string) $this->justifiable_type !== Action::class) {
            return;
        }

        $category = strtolower(trim((string) $this->categorie));
        if (! in_array($category, self::EXECUTION_CATEGORIES, true) && $this->sous_action_id === null) {
            return;
        }

        $updated = ActionLog::query()
            ->where('action_id', (int) $this->justifiable_id)
            ->where('type_evenement', 'justificatif_absent')
            ->whereIn('niveau', ['warning', 'critical', 'urgence'])
            ->update([
                'niveau' => 'info',
                'message' => 'Justificatif d execution depose: l alerte de justificatif absent est resolue.',
                'details' => [
                    'resolved' => true,
                    'resolved_at' => now()->toIso8601String(),
                    'resolved_by_justificatif_id' => (int) $this->id,
                    'categorie' => (string) $this->categorie,
                ],
                'lu' => true,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            if (Cache::increment('alert-center:version') === false) {
                Cache::forever('alert-center:version', 2);
            }

            app(AnalyticsCacheVersionService::class)->bumpAll();
        }
    }
}
