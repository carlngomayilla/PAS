<?php

namespace App\Services\Governance;

use App\Models\ActionLog;
use App\Models\DataArchive;
use App\Models\Justificatif;
use App\Models\Pas;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetentionService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $policies = $this->policies();

        return [
            'policies' => $policies,
            'counts' => [
                'pas' => $this->pasCandidatesQuery()->count(),
                'justificatifs' => $this->justificatifCandidatesQuery()->count(),
                'action_logs' => $this->actionLogCandidatesQuery()->count(),
                'notifications' => $this->notificationCandidatesCount(),
            ],
            'recent_archives' => DataArchive::query()
                ->latest('archived_at')
                ->limit(15)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(bool $execute = false, ?User $actor = null): array
    {
        $summary = $this->summary();
        if (! $execute) {
            $summary['mode'] = 'dry-run';

            return $summary;
        }

        $batchKey = 'RET-'.Str::upper(Str::random(10));
        $created = [
            'pas' => 0,
            'justificatifs' => 0,
            'action_logs' => 0,
            'notifications' => 0,
        ];

        DB::transaction(function () use (&$created, $batchKey, $actor): void {
            foreach ($this->pasCandidatesQuery()->with(['axes.objectifs', 'directions:id,code,libelle', 'paos.ptas.actions'])->get() as $pas) {
                DataArchive::query()->create([
                    'entity_type' => 'pas',
                    'entity_id' => (int) $pas->id,
                    'source_table' => 'pas',
                    'scope_label' => (string) $pas->titre,
                    'batch_key' => $batchKey,
                    'payload' => [
                        'titre' => $pas->titre,
                        'periode_debut' => $pas->periode_debut,
                        'periode_fin' => $pas->periode_fin,
                        'statut' => $pas->statut,
                        'axes' => $pas->axes->map(fn ($axe): array => [
                            'code' => $axe->code,
                            'libelle' => $axe->libelle,
                            'objectifs' => $axe->objectifs->map(fn ($objectif): array => [
                                'code' => $objectif->code,
                                'libelle' => $objectif->libelle,
                                'indicateur_global' => $objectif->indicateur_global,
                                'valeur_cible' => $objectif->valeur_cible,
                            ])->values()->all(),
                        ])->values()->all(),
                        'directions_attendues' => $pas->directions
                            ->map(fn ($direction): array => [
                                'code' => $direction->code,
                                'libelle' => $direction->libelle,
                            ])
                            ->values()
                            ->all(),
                        'paos_total' => $pas->paos->count(),
                    ],
                    'archived_at' => now(),
                    'archived_by' => $actor?->id,
                ]);
                $created['pas']++;
            }

            foreach ($this->justificatifCandidatesQuery()->get() as $justificatif) {
                DataArchive::query()->create([
                    'entity_type' => 'justificatif',
                    'entity_id' => (int) $justificatif->id,
                    'source_table' => 'justificatifs',
                    'scope_label' => (string) ($justificatif->nom_original ?: 'Justificatif'),
                    'batch_key' => $batchKey,
                    'payload' => [
                        'categorie' => $justificatif->categorie,
                        'nom_original' => $justificatif->nom_original,
                        'chemin_stockage' => $justificatif->chemin_stockage,
                        'mime_type' => $justificatif->mime_type,
                        'taille_octets' => $justificatif->taille_octets,
                        'justifiable_type' => $justificatif->justifiable_type,
                        'justifiable_id' => $justificatif->justifiable_id,
                        'action_week_id' => $justificatif->action_week_id,
                        'created_at' => optional($justificatif->created_at)->toIso8601String(),
                    ],
                    'archived_at' => now(),
                    'archived_by' => $actor?->id,
                ]);
                $created['justificatifs']++;
            }

            foreach ($this->actionLogCandidatesQuery()->get() as $log) {
                DataArchive::query()->create([
                    'entity_type' => 'action_log',
                    'entity_id' => (int) $log->id,
                    'source_table' => 'action_logs',
                    'scope_label' => (string) $log->type_evenement,
                    'batch_key' => $batchKey,
                    'payload' => [
                        'action_id' => $log->action_id,
                        'action_week_id' => $log->action_week_id,
                        'niveau' => $log->niveau,
                        'type_evenement' => $log->type_evenement,
                        'message' => $log->message,
                        'details' => $log->details,
                        'cible_role' => $log->cible_role,
                        'utilisateur_id' => $log->utilisateur_id,
                        'created_at' => optional($log->created_at)->toIso8601String(),
                    ],
                    'archived_at' => now(),
                    'archived_by' => $actor?->id,
                ]);
                $created['action_logs']++;
            }

            $notifications = $this->notificationCandidates()
                ->get()
                ->map(static fn ($row): array => (array) $row);

            foreach ($notifications as $notification) {
                DataArchive::query()->create([
                    'entity_type' => 'notification',
                    'entity_id' => null,
                    'source_table' => 'notifications',
                    'scope_label' => (string) ($notification['id'] ?? ($notification['type'] ?? 'notification')),
                    'batch_key' => $batchKey,
                    'payload' => $notification,
                    'archived_at' => now(),
                    'archived_by' => $actor?->id,
                ]);
                $created['notifications']++;
            }
        });

        return [
            'mode' => 'execute',
            'batch_key' => $batchKey,
            'created' => $created,
            'summary' => $this->summary(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function policies(): array
    {
        return [
            'pas_years_after_end' => (int) config('retention.pas_years_after_end', 5),
            'justificatifs_days' => (int) config('retention.justificatifs_days', 1825),
            'action_logs_days' => (int) config('retention.action_logs_days', 1095),
            'notifications_days' => (int) config('retention.notifications_days', 365),
        ];
    }

    private function pasCandidatesQuery(): Builder
    {
        $thresholdYear = Carbon::today()->year - (int) config('retention.pas_years_after_end', 5);

        return Pas::query()
            ->where('periode_fin', '<=', $thresholdYear)
            ->whereNotIn('id', function ($query): void {
                $query->select('entity_id')
                    ->from('data_archives')
                    ->where('source_table', 'pas')
                    ->where('entity_type', 'pas')
                    ->whereNotNull('entity_id');
            });
    }

    private function justificatifCandidatesQuery(): Builder
    {
        $cutoff = Carbon::today()->subDays((int) config('retention.justificatifs_days', 1825));

        return Justificatif::query()
            ->where('created_at', '<=', $cutoff)
            ->whereNotIn('id', function ($query): void {
                $query->select('entity_id')
                    ->from('data_archives')
                    ->where('source_table', 'justificatifs')
                    ->where('entity_type', 'justificatif')
                    ->whereNotNull('entity_id');
            });
    }

    private function actionLogCandidatesQuery(): Builder
    {
        $cutoff = Carbon::today()->subDays((int) config('retention.action_logs_days', 1095));

        return ActionLog::query()
            ->where('created_at', '<=', $cutoff)
            ->whereNotIn('id', function ($query): void {
                $query->select('entity_id')
                    ->from('data_archives')
                    ->where('source_table', 'action_logs')
                    ->where('entity_type', 'action_log')
                    ->whereNotNull('entity_id');
            });
    }

    private function notificationCandidatesCount(): int
    {
        return $this->notificationCandidates()->count();
    }

    private function notificationCandidates()
    {
        $cutoff = Carbon::today()->subDays((int) config('retention.notifications_days', 365));

        return DB::table('notifications')
            ->where('created_at', '<=', $cutoff)
            ->whereNotIn('id', function ($query): void {
                $query->select('scope_label')
                    ->from('data_archives')
                    ->where('source_table', 'notifications')
                    ->where('entity_type', 'notification');
            });
    }
}
